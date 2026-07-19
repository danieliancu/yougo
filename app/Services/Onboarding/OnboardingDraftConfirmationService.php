<?php

namespace App\Services\Onboarding;

use App\DataTransferObjects\Onboarding\ConfirmedSelections;
use App\DataTransferObjects\Onboarding\ImportedFact;
use App\DataTransferObjects\Onboarding\LocationData;
use App\DataTransferObjects\Onboarding\NormalizedExtractionResult;
use App\DataTransferObjects\Onboarding\ServiceData;
use App\Enums\OnboardingDraftStatus;
use App\Enums\OnboardingState;
use App\Exceptions\Onboarding\IncompleteOnboardingDraftException;
use App\Exceptions\Onboarding\MissingFactDecisionsException;
use App\Exceptions\Onboarding\OnboardingRevisionConflictException;
use App\Models\Location;
use App\Models\OnboardingDraft;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Confirms a draft: transactional, idempotent, revision-checked. Only writes business
 * profile fields, locations, and services in Task 1 — staff/FAQ/policies are parsed
 * and retained in normalized_extraction_result but not written to live tables (Task 2).
 *
 * A confirmation that fails validation (missing fact decisions, incomplete data)
 * persists NOTHING: no live data change, no metadata.confirmation write, no status/
 * revision/salon-state change. The only trace is a transient operational log line.
 */
class OnboardingDraftConfirmationService
{
    private const BUSINESS_FIELD_TO_COLUMN = [
        'business.name' => 'name',
        'business.website' => 'website',
        'business.service_at_customer_location' => 'service_at_customer_location',
        'business.opening_hours' => 'opening_hours',
        'business.business_type' => 'business_type',
        'business.description' => 'ai_about_business',
        'contact.business_phone' => 'business_phone',
        'contact.notification_email' => 'notification_email',
    ];

    private const LOCATION_FIELD_MAP = [
        'name' => 'name',
        'address' => 'address',
        'phone' => 'phone',
        'hours' => 'opening_hours',
    ];

    private const RECOGNIZED_LANGUAGE_CODES = [
        'ro' => 'ro', 'ron' => 'ro', 'romana' => 'ro', 'română' => 'ro', 'romanian' => 'ro',
        'en' => 'en', 'eng' => 'en', 'engleza' => 'en', 'engleză' => 'en', 'english' => 'en',
    ];

    public function __construct(
        private readonly OnboardingStateMachine $salonMachine,
        private readonly OnboardingDraftStateMachine $draftMachine,
        private readonly OnboardingHoursValidator $hoursValidator,
        private readonly OnboardingPriceFormatter $priceFormatter,
    ) {}

    public function confirm(OnboardingDraft $draft, User $user, ConfirmedSelections $selections): Salon
    {
        return DB::transaction(function () use ($draft, $user, $selections) {
            $lockedDraft = OnboardingDraft::query()->whereKey($draft->id)->lockForUpdate()->first();

            if (! $lockedDraft) {
                throw new RuntimeException('Draft not found.');
            }

            $salon = Salon::query()->whereKey($lockedDraft->salon_id)->lockForUpdate()->first();

            if (! $salon) {
                throw new RuntimeException('Salon not found.');
            }

            if ($lockedDraft->status === OnboardingDraftStatus::Confirmed) {
                return $salon; // idempotent no-op — a confirmed draft has nothing left to conflict over.
            }

            if ($lockedDraft->revision !== $selections->expectedRevision) {
                throw new OnboardingRevisionConflictException($lockedDraft->revision);
            }

            $result = NormalizedExtractionResult::fromArray($lockedDraft->normalized_extraction_result ?? []);

            $missing = $this->collectMissingFactDecisions($result, $selections);

            if ($missing !== []) {
                throw new MissingFactDecisionsException($missing);
            }

            $resolvedBusiness = $this->resolveFields($result->business?->factMap() ?? [], 'business', $selections);
            $resolvedContact = $this->resolveFields($result->contact?->factMap() ?? [], 'contact', $selections);
            $resolvedBusinessFields = [...$resolvedBusiness, ...$resolvedContact];

            $activeLocations = array_values(array_filter(
                $result->locations,
                fn (LocationData $location) => ! ($location->fingerprint && $selections->isLocationExcluded($location->fingerprint))
            ));
            $activeServices = array_values(array_filter(
                $result->services,
                fn (ServiceData $service) => ! ($service->fingerprint && $selections->isServiceExcluded($service->fingerprint))
            ));

            $resolvedLocations = array_map(
                fn (LocationData $location) => $this->resolveFields($location->factMap(), "locations.{$location->fingerprint}", $selections),
                $activeLocations
            );
            $resolvedServices = array_map(
                fn (ServiceData $service) => $this->resolveFields($service->factMap(), "services.{$service->fingerprint}", $selections),
                $activeServices
            );

            $failedConditions = $this->checkCompleteness($salon, $resolvedBusinessFields, $resolvedLocations, $resolvedServices);

            if ($failedConditions !== []) {
                throw new IncompleteOnboardingDraftException($failedConditions);
            }

            $auditFields = $this->applyBusinessFields($salon, $resolvedBusinessFields, $selections);

            $metadata = $lockedDraft->metadata ?? [];
            $locationMap = $metadata['confirmation']['location_map'] ?? [];
            $serviceMap = $metadata['confirmation']['service_map'] ?? [];
            $conflicts = [];

            foreach ($activeLocations as $index => $location) {
                $resolution = $this->resolveLocation($location, $resolvedLocations[$index], $salon, $locationMap, $conflicts);
                $locationMap[$location->fingerprint] = $resolution['id'];
            }

            foreach ($activeServices as $index => $service) {
                $defaultLocationId = count($locationMap) === 1 ? array_values($locationMap)[0] : null;
                $resolution = $this->resolveService($service, $resolvedServices[$index], $salon, $serviceMap, $conflicts, $defaultLocationId);
                $serviceMap[$service->fingerprint] = $resolution['id'];
            }

            $metadata['confirmation'] = [
                'facts' => $auditFields,
                'location_map' => $locationMap,
                'service_map' => $serviceMap,
                'conflicts' => $conflicts,
                'revision' => $lockedDraft->revision,
                'confirmed_by_user_id' => $user->id,
                'confirmed_at' => now()->toISOString(),
            ];

            $lockedDraft->forceFill([
                'metadata' => $metadata,
                'confirmed_by_user_id' => $user->id,
                'confirmed_at' => now(),
            ])->save();

            $this->draftMachine->transition($lockedDraft, OnboardingDraftStatus::Confirmed);
            $this->salonMachine->transition($salon, OnboardingState::IdentityReady);

            return $salon->refresh();
        });
    }

    /**
     * @return list<string>
     */
    private function collectMissingFactDecisions(NormalizedExtractionResult $result, ConfirmedSelections $selections): array
    {
        $missing = [];

        foreach (($result->business?->factMap() ?? []) as $field => $fact) {
            $this->checkRequired($fact, "business.{$field}", $selections, $missing);
        }

        foreach (($result->contact?->factMap() ?? []) as $field => $fact) {
            $this->checkRequired($fact, "contact.{$field}", $selections, $missing);
        }

        foreach ($result->locations as $location) {
            if ($location->fingerprint && $selections->isLocationExcluded($location->fingerprint)) {
                continue;
            }

            foreach ($location->factMap() as $field => $fact) {
                $this->checkRequired($fact, "locations.{$location->fingerprint}.{$field}", $selections, $missing);
            }
        }

        foreach ($result->services as $service) {
            if ($service->fingerprint && $selections->isServiceExcluded($service->fingerprint)) {
                continue;
            }

            foreach ($service->factMap() as $field => $fact) {
                $this->checkRequired($fact, "services.{$service->fingerprint}.{$field}", $selections, $missing);
            }
        }

        return $missing;
    }

    /**
     * @param  list<string>  $missing
     */
    private function checkRequired(?ImportedFact $fact, string $path, ConfirmedSelections $selections, array &$missing): void
    {
        if ($fact === null || ! $fact->requiresConfirmation) {
            return;
        }

        if ($selections->decisionFor($path) === null) {
            $missing[] = $path;
        }
    }

    /**
     * @param  array<string, ?ImportedFact>  $factMap
     * @return array<string, mixed>
     */
    private function resolveFields(array $factMap, string $pathPrefix, ConfirmedSelections $selections): array
    {
        $resolved = [];

        foreach ($factMap as $field => $fact) {
            if ($fact === null) {
                continue;
            }

            $decision = $selections->decisionFor("{$pathPrefix}.{$field}");

            if ($decision !== null) {
                if ($decision['decision'] === ConfirmedSelections::DECISION_EXCLUDED) {
                    continue;
                }

                $resolved[$field] = $decision['decision'] === ConfirmedSelections::DECISION_CORRECTED
                    ? $decision['value']
                    : $fact->value;

                continue;
            }

            $resolved[$field] = $fact->value;
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $resolvedBusinessFields
     * @param  list<array<string, mixed>>  $resolvedLocations
     * @param  list<array<string, mixed>>  $resolvedServices
     * @return list<string>
     */
    private function checkCompleteness(Salon $salon, array $resolvedBusinessFields, array $resolvedLocations, array $resolvedServices): array
    {
        $failed = [];

        $name = $resolvedBusinessFields['name'] ?? null;

        if (blank($name) && blank($salon->name)) {
            $failed[] = 'business_name_missing';
        }

        $serviceAtCustomerLocation = (bool) ($resolvedBusinessFields['service_at_customer_location'] ?? $salon->service_at_customer_location);
        $hasLocation = count($resolvedLocations) > 0;

        if (! $hasLocation && ! $serviceAtCustomerLocation) {
            $failed[] = 'no_location_and_no_customer_service_area';
        }

        $locationHasHours = false;

        foreach ($resolvedLocations as $resolvedLocation) {
            $hours = $resolvedLocation['opening_hours'] ?? null;

            if (is_array($hours) && $this->hoursValidator->hasAnyScheduledDay($hours)) {
                $locationHasHours = true;
                break;
            }
        }

        $businessHours = $resolvedBusinessFields['opening_hours'] ?? null;
        $businessHasHours = $serviceAtCustomerLocation && is_array($businessHours) && $this->hoursValidator->hasAnyScheduledDay($businessHours);

        if (! $locationHasHours && ! $businessHasHours) {
            $failed[] = 'opening_hours_missing';
        }

        if (count($resolvedServices) === 0) {
            $failed[] = 'no_services';
        }

        return $failed;
    }

    /**
     * @param  array<string, mixed>  $resolvedBusinessFields
     * @return array<string, mixed>
     */
    private function applyBusinessFields(Salon $salon, array $resolvedBusinessFields, ConfirmedSelections $selections): array
    {
        $audit = [];

        foreach (self::BUSINESS_FIELD_TO_COLUMN as $path => $column) {
            $field = str_contains($path, '.') ? substr($path, strpos($path, '.') + 1) : $path;

            if (! array_key_exists($field, $resolvedBusinessFields)) {
                continue;
            }

            $value = $resolvedBusinessFields[$field];
            $overwrite = $selections->shouldOverwrite($column);

            if ($column === 'opening_hours' && is_array($value)) {
                $value = $this->hoursValidator->validate($value);
            }

            if ($column === 'service_at_customer_location') {
                // blank(false) === false in Laravel, so the usual "only fill if blank"
                // rule can't distinguish "never set" from "manually set to false" for
                // this boolean column (which defaults to false). Filling in `true` is
                // always safe (it only adds capability info); flipping an already-true
                // value back to false requires an explicit overwrite.
                if ((bool) $value === true) {
                    if ($salon->service_at_customer_location !== true || $overwrite) {
                        $salon->service_at_customer_location = true;
                        $audit[$path] = ['applied' => true, 'value' => true];
                    }
                } elseif ($overwrite) {
                    $salon->service_at_customer_location = false;
                    $audit[$path] = ['applied' => true, 'value' => false];
                }

                continue;
            }

            if (blank($salon->{$column}) || $overwrite) {
                $salon->{$column} = $value;
                $audit[$path] = ['applied' => true, 'value' => $value, 'overwrite' => $overwrite];
            } else {
                $audit[$path] = ['applied' => false, 'reason' => 'live_value_already_set'];
            }
        }

        // business.languages -> Salon::ai_language_mode is not a 1:1 column mapping
        // (it needs interpreting), so it's handled separately from the generic loop
        // above. Only written when the resolved value unambiguously names a single
        // recognized language (ro|en) — a multi-language or unrecognized value is left
        // in the draft only, since ai_language_mode also drives live AI behavior.
        if (array_key_exists('languages', $resolvedBusinessFields)) {
            $languageCode = $this->resolveLanguageMode($resolvedBusinessFields['languages']);
            $overwrite = $selections->shouldOverwrite('ai_language_mode');

            if ($languageCode !== null && (blank($salon->ai_language_mode) || $salon->ai_language_mode === 'auto' || $overwrite)) {
                $salon->ai_language_mode = $languageCode;
                $audit['business.languages'] = ['applied' => true, 'value' => $languageCode];
            } else {
                $audit['business.languages'] = ['applied' => false, 'reason' => $languageCode === null ? 'ambiguous_or_unrecognized' : 'live_value_already_set'];
            }
        }

        $salon->save();

        return $audit;
    }

    /**
     * Returns a recognized single language code (ro|en) only when every language
     * candidate present resolves to the same code — a mix of Romanian and English (or
     * anything unrecognized) is treated as ambiguous and returns null.
     */
    private function resolveLanguageMode(mixed $value): ?string
    {
        $candidates = is_array($value) ? $value : [$value];
        $codes = [];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $normalized = mb_strtolower(trim($candidate));

            if (isset(self::RECOGNIZED_LANGUAGE_CODES[$normalized])) {
                $codes[] = self::RECOGNIZED_LANGUAGE_CODES[$normalized];
            }
        }

        $unique = array_values(array_unique($codes));

        return count($unique) === 1 ? $unique[0] : null;
    }

    /**
     * @param  array<string, mixed>  $resolved
     * @param  array<string, int>  $locationMap
     * @return array{id: int}
     */
    private function resolveLocation(LocationData $location, array $resolved, Salon $salon, array &$locationMap, array &$conflicts): array
    {
        $fingerprint = $location->fingerprint;

        if ($fingerprint && isset($locationMap[$fingerprint])) {
            $existing = Location::query()->where('salon_id', $salon->id)->find($locationMap[$fingerprint]);

            if ($existing) {
                $this->fillIfEmpty($existing, $resolved, self::LOCATION_FIELD_MAP);

                return ['id' => $existing->id];
            }
        }

        if (! $location->isTemporaryFingerprint) {
            $match = $this->matchLocation($resolved, $salon, $conflicts, $fingerprint);

            if ($match) {
                $this->fillIfEmpty($match, $resolved, self::LOCATION_FIELD_MAP);

                return ['id' => $match->id];
            }
        }

        $created = $salon->locations()->create([
            'name' => $resolved['name'] ?? '',
            'address' => $resolved['address'] ?? '',
            'phone' => $resolved['phone'] ?? null,
            'hours' => $resolved['opening_hours'] ?? null,
        ]);

        return ['id' => $created->id];
    }

    private function matchLocation(array $resolved, Salon $salon, array &$conflicts, ?string $fingerprint): ?Location
    {
        $resolvedName = $this->normalizeText($resolved['name'] ?? null);
        $resolvedAddress = $this->normalizeText($resolved['address'] ?? null);
        $resolvedPhone = $this->normalizePhone($resolved['phone'] ?? null);

        $candidates = $salon->locations()->get();

        if ($resolvedAddress !== '' && $resolvedPhone !== '') {
            foreach ($candidates as $candidate) {
                if ($this->normalizeText($candidate->address) === $resolvedAddress
                    && $this->normalizePhone($candidate->phone) === $resolvedPhone) {
                    return $candidate;
                }
            }
        }

        if ($resolvedName !== '') {
            $nameMatches = $candidates->filter(fn (Location $candidate) => $this->normalizeText($candidate->name) === $resolvedName);

            if ($nameMatches->count() === 1) {
                $candidate = $nameMatches->first();
                $candidateAddress = $this->normalizeText($candidate->address);
                $candidatePhone = $this->normalizePhone($candidate->phone);

                $addressConflicts = $resolvedAddress !== '' && $candidateAddress !== '' && $candidateAddress !== $resolvedAddress;
                $phoneConflicts = $resolvedPhone !== '' && $candidatePhone !== '' && $candidatePhone !== $resolvedPhone;

                if (! $addressConflicts && ! $phoneConflicts) {
                    return $candidate;
                }

                $conflicts[] = ['type' => 'location', 'fingerprint' => $fingerprint, 'reason' => 'name_matched_but_fields_conflict'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $resolved
     * @param  array<string, int>  $serviceMap
     * @return array{id: int}
     */
    private function resolveService(ServiceData $service, array $resolved, Salon $salon, array &$serviceMap, array &$conflicts, ?int $defaultLocationId): array
    {
        $fingerprint = $service->fingerprint;

        if ($fingerprint && isset($serviceMap[$fingerprint])) {
            $existing = Service::query()->where('salon_id', $salon->id)->find($serviceMap[$fingerprint]);

            if ($existing) {
                $this->fillIfEmptyService($existing, $resolved);

                return ['id' => $existing->id];
            }
        }

        if (! $service->isTemporaryFingerprint) {
            $match = $this->matchService($resolved, $salon, $conflicts, $fingerprint);

            if ($match) {
                $this->fillIfEmptyService($match, $resolved);

                return ['id' => $match->id];
            }
        }

        $created = $salon->services()->create([
            'name' => $resolved['name'] ?? '',
            'type' => $resolved['category'] ?? '',
            'price' => $this->priceFormatter->toDisplayString($resolved['price'] ?? null),
            'currency' => $resolved['currency'] ?? null,
            'duration' => $resolved['duration'] ?? 30,
            'notes' => $resolved['description'] ?? null,
            'location_ids' => $defaultLocationId ? [$defaultLocationId] : [],
        ]);

        return ['id' => $created->id];
    }

    private function matchService(array $resolved, Salon $salon, array &$conflicts, ?string $fingerprint): ?Service
    {
        $resolvedName = $this->normalizeText($resolved['name'] ?? null);
        $resolvedCategory = $this->normalizeText($resolved['category'] ?? null);

        $candidates = $salon->services()->get();

        if ($resolvedName !== '' && $resolvedCategory !== '') {
            foreach ($candidates as $candidate) {
                if ($this->normalizeText($candidate->name) === $resolvedName
                    && $this->normalizeText($candidate->type) === $resolvedCategory) {
                    return $candidate;
                }
            }
        }

        if ($resolvedName !== '') {
            $nameMatches = $candidates->filter(fn (Service $candidate) => $this->normalizeText($candidate->name) === $resolvedName);

            if ($nameMatches->count() === 1) {
                $candidate = $nameMatches->first();
                $candidateCategory = $this->normalizeText($candidate->type);

                $categoryConflicts = $resolvedCategory !== '' && $candidateCategory !== '' && $candidateCategory !== $resolvedCategory;

                if (! $categoryConflicts) {
                    return $candidate;
                }

                $conflicts[] = ['type' => 'service', 'fingerprint' => $fingerprint, 'reason' => 'name_matched_but_fields_conflict'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $resolved
     * @param  array<string, string>  $columnToSourceField
     */
    private function fillIfEmpty(Location $location, array $resolved, array $columnToSourceField): void
    {
        $dirty = false;

        foreach ($columnToSourceField as $column => $sourceField) {
            if (! array_key_exists($sourceField, $resolved)) {
                continue;
            }

            if (blank($location->{$column})) {
                $location->{$column} = $resolved[$sourceField];
                $dirty = true;
            }
        }

        if ($dirty) {
            $location->save();
        }
    }

    /**
     * @param  array<string, mixed>  $resolved
     */
    private function fillIfEmptyService(Service $service, array $resolved): void
    {
        $map = ['name' => 'name', 'type' => 'category', 'price' => 'price', 'currency' => 'currency', 'duration' => 'duration', 'notes' => 'description'];
        $dirty = false;

        foreach ($map as $column => $sourceField) {
            if (! array_key_exists($sourceField, $resolved)) {
                continue;
            }

            if (blank($service->{$column})) {
                $service->{$column} = $sourceField === 'price' ? $this->priceFormatter->toDisplayString($resolved[$sourceField]) : $resolved[$sourceField];
                $dirty = true;
            }
        }

        if ($dirty) {
            $service->save();
        }
    }

    private function normalizeText(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        return Str::of($value)->ascii()->lower()->squish()->toString();
    }

    private function normalizePhone(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        return preg_replace('/[^0-9+]/', '', $value) ?? '';
    }
}
