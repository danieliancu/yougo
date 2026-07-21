<?php

namespace App\Services\Onboarding;

use App\DataTransferObjects\Onboarding\ConfirmedSelections;
use App\DataTransferObjects\Onboarding\FaqEntryData;
use App\DataTransferObjects\Onboarding\ImportedFact;
use App\DataTransferObjects\Onboarding\LocationData;
use App\DataTransferObjects\Onboarding\NormalizedExtractionResult;
use App\DataTransferObjects\Onboarding\PolicyEntryData;
use App\DataTransferObjects\Onboarding\ServiceData;
use App\DataTransferObjects\Onboarding\StaffData;
use App\Enums\OnboardingDraftStatus;
use App\Enums\OnboardingState;
use App\Exceptions\Onboarding\IncompleteOnboardingDraftException;
use App\Exceptions\Onboarding\MissingFactDecisionsException;
use App\Exceptions\Onboarding\OnboardingRevisionConflictException;
use App\Models\Faq;
use App\Models\Location;
use App\Models\OnboardingDraft;
use App\Models\Policy;
use App\Models\Salon;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Confirms a draft: transactional, idempotent, revision-checked. Writes business
 * profile fields, locations, services, staff, FAQ, and policies. Staff/FAQ/policies are
 * always optional/best-effort and never participate in checkCompleteness().
 *
 * A confirmation that fails validation (missing fact decisions, incomplete data)
 * persists NOTHING: no live data change, no metadata.confirmation write, no status/
 * revision/salon-state change. The only trace is a transient operational log line.
 */
class OnboardingDraftConfirmationService
{
    private const DEFAULT_SERVICE_DURATION_MINUTES = 30;

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
            $activeStaff = array_values(array_filter(
                $result->staff,
                fn (StaffData $member) => ! ($member->fingerprint && $selections->isStaffExcluded($member->fingerprint))
            ));
            $activeFaq = array_values(array_filter(
                $result->faq,
                fn (FaqEntryData $entry) => ! ($entry->fingerprint && $selections->isFaqExcluded($entry->fingerprint))
            ));
            $activePolicies = array_values(array_filter(
                $result->policies,
                fn (PolicyEntryData $entry) => ! ($entry->fingerprint && $selections->isPolicyExcluded($entry->fingerprint))
            ));

            $resolvedLocations = array_map(
                fn (LocationData $location) => $this->resolveFields($location->factMap(), "locations.{$location->fingerprint}", $selections),
                $activeLocations
            );
            $resolvedServices = array_map(
                fn (ServiceData $service) => $this->resolveFields($service->factMap(), "services.{$service->fingerprint}", $selections),
                $activeServices
            );
            $resolvedStaff = array_map(
                fn (StaffData $member) => $this->resolveFields($member->factMap(), "staff.{$member->fingerprint}", $selections),
                $activeStaff
            );
            $resolvedFaq = array_map(
                fn (FaqEntryData $entry) => $this->resolveFields($entry->factMap(), "faq.{$entry->fingerprint}", $selections),
                $activeFaq
            );
            $resolvedPolicies = array_map(
                fn (PolicyEntryData $entry) => $this->resolveFields($entry->factMap(), "policies.{$entry->fingerprint}", $selections),
                $activePolicies
            );

            $failedConditions = $this->checkCompleteness($salon, $resolvedBusinessFields, $resolvedLocations, $resolvedServices);

            if ($failedConditions !== []) {
                throw new IncompleteOnboardingDraftException($failedConditions);
            }

            $auditFields = $this->applyBusinessFields($salon, $resolvedBusinessFields, $selections);

            $metadata = $lockedDraft->metadata ?? [];
            $locationMap = $metadata['confirmation']['location_map'] ?? [];
            $serviceMap = $metadata['confirmation']['service_map'] ?? [];
            $staffMap = $metadata['confirmation']['staff_map'] ?? [];
            $faqMap = $metadata['confirmation']['faq_map'] ?? [];
            $policyMap = $metadata['confirmation']['policy_map'] ?? [];
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

            $this->mergeServiceCategories($salon, $resolvedServices);

            foreach ($activeStaff as $index => $member) {
                $defaultLocationId = count($locationMap) === 1 ? array_values($locationMap)[0] : null;
                $resolution = $this->resolveStaff($member, $resolvedStaff[$index], $salon, $staffMap, $conflicts, $defaultLocationId);
                $staffMap[$member->fingerprint] = $resolution['id'];
            }

            foreach ($activeFaq as $index => $entry) {
                $resolution = $this->resolveFaq($entry, $resolvedFaq[$index], $salon, $faqMap, $conflicts);
                $faqMap[$entry->fingerprint] = $resolution['id'];
            }

            foreach ($activePolicies as $index => $entry) {
                $resolution = $this->resolvePolicy($entry, $resolvedPolicies[$index], $salon, $policyMap, $conflicts);
                $policyMap[$entry->fingerprint] = $resolution['id'];
            }

            $metadata['confirmation'] = [
                'facts' => $auditFields,
                'location_map' => $locationMap,
                'service_map' => $serviceMap,
                'staff_map' => $staffMap,
                'faq_map' => $faqMap,
                'policy_map' => $policyMap,
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

        foreach ($result->staff as $member) {
            if ($member->fingerprint && $selections->isStaffExcluded($member->fingerprint)) {
                continue;
            }

            foreach ($member->factMap() as $field => $fact) {
                $this->checkRequired($fact, "staff.{$member->fingerprint}.{$field}", $selections, $missing);
            }
        }

        foreach ($result->faq as $entry) {
            if ($entry->fingerprint && $selections->isFaqExcluded($entry->fingerprint)) {
                continue;
            }

            foreach ($entry->factMap() as $field => $fact) {
                $this->checkRequired($fact, "faq.{$entry->fingerprint}.{$field}", $selections, $missing);
            }
        }

        foreach ($result->policies as $entry) {
            if ($entry->fingerprint && $selections->isPolicyExcluded($entry->fingerprint)) {
                continue;
            }

            foreach ($entry->factMap() as $field => $fact) {
                $this->checkRequired($fact, "policies.{$entry->fingerprint}.{$field}", $selections, $missing);
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

        // Opening hours are deliberately NOT required to finish the import — a business
        // can be onboarded without them and add them later from /dashboard/locations.
        // The real gate is OnboardingChecklistService's "opening_hours" step, checked
        // when the salon actually goes live (booking needs hours; import doesn't).

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
            'duration' => $this->sanitizeDuration($resolved['duration'] ?? null),
            'notes' => $resolved['description'] ?? null,
            'location_ids' => $defaultLocationId ? [$defaultLocationId] : [],
        ]);

        return ['id' => $created->id];
    }

    /**
     * `services.duration` is an unsigned integer of minutes; the AI-extracted fact is
     * free text and occasionally describes something else entirely (e.g. a maintenance
     * interval like "3-5 luni (întreținere)" rather than an appointment length), which
     * MySQL truncation-errors on rather than silently coercing. Only a value that reads
     * as an actual duration is trusted; anything else falls back to the same default the
     * `services` table column itself uses.
     */
    private function sanitizeDuration(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }

        if (is_float($value)) {
            return max(0, (int) round($value));
        }

        if (is_string($value) && preg_match('/(\d+)\s*(minut|min|or[aă]|h)/iu', $value, $matches)) {
            $amount = (int) $matches[1];
            $unit = mb_strtolower($matches[2]);

            return str_starts_with($unit, 'or') || $unit === 'h' ? $amount * 60 : $amount;
        }

        return self::DEFAULT_SERVICE_DURATION_MINUTES;
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
     * @param  array<string, int>  $staffMap
     * @return array{id: int}
     */
    private function resolveStaff(StaffData $member, array $resolved, Salon $salon, array &$staffMap, array &$conflicts, ?int $defaultLocationId): array
    {
        $fingerprint = $member->fingerprint;

        if ($fingerprint && isset($staffMap[$fingerprint])) {
            $existing = Staff::query()->where('salon_id', $salon->id)->find($staffMap[$fingerprint]);

            if ($existing) {
                $this->fillIfEmptyStaff($existing, $resolved);

                return ['id' => $existing->id];
            }
        }

        if (! $member->isTemporaryFingerprint) {
            $match = $this->matchStaff($resolved, $salon, $conflicts, $fingerprint);

            if ($match) {
                $this->fillIfEmptyStaff($match, $resolved);

                return ['id' => $match->id];
            }
        }

        $created = $salon->staff()->create([
            'location_id' => $defaultLocationId,
            'name' => $resolved['name'] ?? '',
            'role' => $resolved['role'] ?? null,
        ]);

        return ['id' => $created->id];
    }

    private function matchStaff(array $resolved, Salon $salon, array &$conflicts, ?string $fingerprint): ?Staff
    {
        $resolvedName = $this->normalizeText($resolved['name'] ?? null);
        $resolvedRole = $this->normalizeText($resolved['role'] ?? null);

        $candidates = $salon->staff()->get();

        if ($resolvedName !== '' && $resolvedRole !== '') {
            foreach ($candidates as $candidate) {
                if ($this->normalizeText($candidate->name) === $resolvedName
                    && $this->normalizeText($candidate->role) === $resolvedRole) {
                    return $candidate;
                }
            }
        }

        if ($resolvedName !== '') {
            $nameMatches = $candidates->filter(fn (Staff $candidate) => $this->normalizeText($candidate->name) === $resolvedName);

            if ($nameMatches->count() === 1) {
                $candidate = $nameMatches->first();
                $candidateRole = $this->normalizeText($candidate->role);

                $roleConflicts = $resolvedRole !== '' && $candidateRole !== '' && $candidateRole !== $resolvedRole;

                if (! $roleConflicts) {
                    return $candidate;
                }

                $conflicts[] = ['type' => 'staff', 'fingerprint' => $fingerprint, 'reason' => 'name_matched_but_fields_conflict'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $resolved
     * @param  array<string, int>  $faqMap
     * @return array{id: int}
     */
    private function resolveFaq(FaqEntryData $entry, array $resolved, Salon $salon, array &$faqMap, array &$conflicts): array
    {
        $fingerprint = $entry->fingerprint;

        if ($fingerprint && isset($faqMap[$fingerprint])) {
            $existing = Faq::query()->where('salon_id', $salon->id)->find($faqMap[$fingerprint]);

            if ($existing) {
                $this->fillIfEmptyFaq($existing, $resolved);

                return ['id' => $existing->id];
            }
        }

        if (! $entry->isTemporaryFingerprint) {
            $match = $this->matchFaq($resolved, $salon);

            if ($match) {
                $this->fillIfEmptyFaq($match, $resolved);

                return ['id' => $match->id];
            }
        }

        $created = $salon->faqs()->create([
            'question' => $resolved['question'] ?? '',
            'answer' => $resolved['answer'] ?? null,
        ]);

        return ['id' => $created->id];
    }

    private function matchFaq(array $resolved, Salon $salon): ?Faq
    {
        $resolvedQuestion = $this->normalizeText($resolved['question'] ?? null);

        if ($resolvedQuestion === '') {
            return null;
        }

        return $salon->faqs()->get()->first(
            fn (Faq $candidate) => $this->normalizeText($candidate->question) === $resolvedQuestion
        );
    }

    /**
     * @param  array<string, mixed>  $resolved
     * @param  array<string, int>  $policyMap
     * @return array{id: int}
     */
    private function resolvePolicy(PolicyEntryData $entry, array $resolved, Salon $salon, array &$policyMap, array &$conflicts): array
    {
        $fingerprint = $entry->fingerprint;

        if ($fingerprint && isset($policyMap[$fingerprint])) {
            $existing = Policy::query()->where('salon_id', $salon->id)->find($policyMap[$fingerprint]);

            if ($existing) {
                $this->fillIfEmptyPolicy($existing, $resolved);

                return ['id' => $existing->id];
            }
        }

        if (! $entry->isTemporaryFingerprint) {
            $match = $this->matchPolicy($resolved, $salon);

            if ($match) {
                $this->fillIfEmptyPolicy($match, $resolved);

                return ['id' => $match->id];
            }
        }

        $created = $salon->policies()->create([
            'title' => $resolved['title'] ?? '',
            'content' => $resolved['content'] ?? null,
        ]);

        return ['id' => $created->id];
    }

    private function matchPolicy(array $resolved, Salon $salon): ?Policy
    {
        $resolvedTitle = $this->normalizeText($resolved['title'] ?? null);

        if ($resolvedTitle === '') {
            return null;
        }

        return $salon->policies()->get()->first(
            fn (Policy $candidate) => $this->normalizeText($candidate->title) === $resolvedTitle
        );
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

    /**
     * @param  array<string, mixed>  $resolved
     */
    private function fillIfEmptyStaff(Staff $staff, array $resolved): void
    {
        $map = ['name' => 'name', 'role' => 'role'];
        $dirty = false;

        foreach ($map as $column => $sourceField) {
            if (! array_key_exists($sourceField, $resolved)) {
                continue;
            }

            if (blank($staff->{$column})) {
                $staff->{$column} = $resolved[$sourceField];
                $dirty = true;
            }
        }

        if ($dirty) {
            $staff->save();
        }
    }

    /**
     * @param  array<string, mixed>  $resolved
     */
    private function fillIfEmptyFaq(Faq $faq, array $resolved): void
    {
        $map = ['question' => 'question', 'answer' => 'answer'];
        $dirty = false;

        foreach ($map as $column => $sourceField) {
            if (! array_key_exists($sourceField, $resolved)) {
                continue;
            }

            if (blank($faq->{$column})) {
                $faq->{$column} = $resolved[$sourceField];
                $dirty = true;
            }
        }

        if ($dirty) {
            $faq->save();
        }
    }

    /**
     * @param  array<string, mixed>  $resolved
     */
    private function fillIfEmptyPolicy(Policy $policy, array $resolved): void
    {
        $map = ['title' => 'title', 'content' => 'content'];
        $dirty = false;

        foreach ($map as $column => $sourceField) {
            if (! array_key_exists($sourceField, $resolved)) {
                continue;
            }

            if (blank($policy->{$column})) {
                $policy->{$column} = $resolved[$sourceField];
                $dirty = true;
            }
        }

        if ($dirty) {
            $policy->save();
        }
    }

    /**
     * Onboarding-created services set Service::type directly (resolveService() below)
     * but that never touched Salon::service_categories — the separate, curated list
     * the "manage categories" screen (ServiceController::updateCategories) reads and
     * writes. Without this, that screen shows an empty/stale list even though every
     * imported service already has a category — and worse, saving that empty list from
     * the manager wipes every service's type back to null, since updateCategories()
     * clears any type not present in what was submitted. Mirrors
     * ServiceImageImportController::mergeServiceCategories().
     *
     * @param  list<array<string, mixed>>  $resolvedServices
     */
    private function mergeServiceCategories(Salon $salon, array $resolvedServices): void
    {
        $categories = collect($resolvedServices)
            ->pluck('category')
            ->filter(fn ($category) => is_string($category) && trim($category) !== '');

        if ($categories->isEmpty()) {
            return;
        }

        $merged = collect($salon->service_categories ?? [])
            ->merge($categories)
            ->map(fn ($category) => trim((string) $category))
            ->filter()
            ->unique(fn (string $category) => $this->normalizeText($category))
            ->values()
            ->all();

        if ($merged !== ($salon->service_categories ?? [])) {
            $salon->update(['service_categories' => $merged]);
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
