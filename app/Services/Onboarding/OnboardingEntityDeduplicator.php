<?php

namespace App\Services\Onboarding;

use App\DataTransferObjects\Onboarding\ImportedFact;
use App\DataTransferObjects\Onboarding\LocationData;
use App\DataTransferObjects\Onboarding\NormalizedExtractionResult;
use App\DataTransferObjects\Onboarding\ServiceData;
use App\Models\OnboardingDraft;
use Illuminate\Support\Str;

/**
 * The only place that assigns fingerprints and merges multi-page mentions of the same
 * location/service. Deliberately separate from the DTOs, which stay limited to
 * representation/validation/serialization and a pure, data-only stableFingerprint().
 * This service is the one that needs to know about the draft (for temporary,
 * draft-scoped fingerprints) — that context does not belong in a DTO.
 *
 * Does not touch the database and does not look at live Location/Service rows —
 * that matching happens later, in OnboardingDraftConfirmationService.
 */
class OnboardingEntityDeduplicator
{
    public function process(OnboardingDraft $draft, NormalizedExtractionResult $result): NormalizedExtractionResult
    {
        return $result->withEntities(
            $this->processLocations($draft, $result->locations),
            $this->processServices($draft, $result->services),
        );
    }

    /**
     * @param  list<LocationData>  $locations
     * @return list<LocationData>
     */
    private function processLocations(OnboardingDraft $draft, array $locations): array
    {
        /** @var array<string, LocationData> $byFingerprint */
        $byFingerprint = [];
        $order = [];

        foreach ($locations as $index => $location) {
            $stable = $location->stableFingerprint();

            if ($stable === null) {
                $temporary = $this->temporaryFingerprint($draft, 'location', $index, $location->name?->value);
                $location = $location->with(
                    name: $this->forceRequiresConfirmation($location->name),
                    fingerprint: $temporary,
                    isTemporaryFingerprint: true,
                );
                $byFingerprint[$temporary] = $location;
                $order[] = $temporary;

                continue;
            }

            if (isset($byFingerprint[$stable])) {
                $byFingerprint[$stable] = $this->mergeLocations($byFingerprint[$stable], $location->with(fingerprint: $stable));
            } else {
                $byFingerprint[$stable] = $location->with(fingerprint: $stable);
                $order[] = $stable;
            }
        }

        return array_map(static fn (string $fp) => $byFingerprint[$fp], $order);
    }

    /**
     * @param  list<ServiceData>  $services
     * @return list<ServiceData>
     */
    private function processServices(OnboardingDraft $draft, array $services): array
    {
        /** @var array<string, ServiceData> $byFingerprint */
        $byFingerprint = [];
        $order = [];

        foreach ($services as $index => $service) {
            $stable = $service->stableFingerprint();

            if ($stable === null) {
                $temporary = $this->temporaryFingerprint($draft, 'service', $index, $service->name?->value);
                $service = $service->with(
                    name: $this->forceRequiresConfirmation($service->name),
                    fingerprint: $temporary,
                    isTemporaryFingerprint: true,
                );
                $byFingerprint[$temporary] = $service;
                $order[] = $temporary;

                continue;
            }

            if (isset($byFingerprint[$stable])) {
                $byFingerprint[$stable] = $this->mergeServices($byFingerprint[$stable], $service->with(fingerprint: $stable));
            } else {
                $byFingerprint[$stable] = $service->with(fingerprint: $stable);
                $order[] = $stable;
            }
        }

        return array_map(static fn (string $fp) => $byFingerprint[$fp], $order);
    }

    private function mergeLocations(LocationData $a, LocationData $b): LocationData
    {
        return $a->with(
            name: $this->mergeFact($a->name, $b->name),
            address: $this->mergeFact($a->address, $b->address),
            city: $this->mergeFact($a->city, $b->city),
            county: $this->mergeFact($a->county, $b->county),
            postcode: $this->mergeFact($a->postcode, $b->postcode),
            country: $this->mergeFact($a->country, $b->country),
            phone: $this->mergeFact($a->phone, $b->phone),
            openingHours: $this->mergeFact($a->openingHours, $b->openingHours),
            sourceUrls: array_values(array_unique([...$a->sourceUrls, ...$b->sourceUrls])),
            externalId: $a->externalId ?? $b->externalId,
        );
    }

    private function mergeServices(ServiceData $a, ServiceData $b): ServiceData
    {
        return $a->with(
            name: $this->mergeFact($a->name, $b->name),
            category: $this->mergeFact($a->category, $b->category),
            description: $this->mergeFact($a->description, $b->description),
            price: $this->mergeFact($a->price, $b->price),
            currency: $this->mergeFact($a->currency, $b->currency),
            duration: $this->mergeFact($a->duration, $b->duration),
            locationAssociations: $this->mergeFact($a->locationAssociations, $b->locationAssociations),
            sourceUrls: array_values(array_unique([...$a->sourceUrls, ...$b->sourceUrls])),
            externalId: $a->externalId ?? $b->externalId,
        );
    }

    /**
     * Same-fingerprint fact merge: identical values keep the first mention; conflicting
     * values are kept as a conflict on the field and force requires_confirmation=true
     * rather than silently picking one.
     */
    private function mergeFact(?ImportedFact $a, ?ImportedFact $b): ?ImportedFact
    {
        if ($a === null) {
            return $b;
        }

        if ($b === null) {
            return $a;
        }

        if ($this->factValuesEqual($a->value, $b->value)) {
            return $a;
        }

        return $a->withConflicts([...$a->conflicts, $b], true);
    }

    private function factValuesEqual(mixed $a, mixed $b): bool
    {
        if (is_string($a) && is_string($b)) {
            return mb_strtolower(trim($a)) === mb_strtolower(trim($b));
        }

        return $a == $b; // phpcs:ignore
    }

    private function forceRequiresConfirmation(?ImportedFact $fact): ?ImportedFact
    {
        return $fact?->withRequiresConfirmation(true);
    }

    private function temporaryFingerprint(OnboardingDraft $draft, string $kind, int $index, mixed $nameValue): string
    {
        $name = is_string($nameValue) && $nameValue !== ''
            ? Str::of($nameValue)->ascii()->lower()->squish()->toString()
            : '';

        return hash('sha256', "{$draft->id}|{$kind}|{$index}|{$name}");
    }
}
