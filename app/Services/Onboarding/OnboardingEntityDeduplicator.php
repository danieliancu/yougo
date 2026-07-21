<?php

namespace App\Services\Onboarding;

use App\DataTransferObjects\Onboarding\BusinessProfileData;
use App\DataTransferObjects\Onboarding\FaqEntryData;
use App\DataTransferObjects\Onboarding\ImportedFact;
use App\DataTransferObjects\Onboarding\LocationData;
use App\DataTransferObjects\Onboarding\NormalizedExtractionResult;
use App\DataTransferObjects\Onboarding\PolicyEntryData;
use App\DataTransferObjects\Onboarding\ServiceData;
use App\DataTransferObjects\Onboarding\StaffData;
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
    private readonly ImportedFactMerger $factMerger;

    private readonly OnboardingHoursValidator $hoursValidator;

    public function __construct(?ImportedFactMerger $factMerger = null, ?OnboardingHoursValidator $hoursValidator = null)
    {
        $this->factMerger = $factMerger ?? new ImportedFactMerger;
        $this->hoursValidator = $hoursValidator ?? new OnboardingHoursValidator;
    }

    public function process(OnboardingDraft $draft, NormalizedExtractionResult $result): NormalizedExtractionResult
    {
        $locations = $this->processLocations($draft, $result->locations, $result->business);

        return $result->withEntities(
            $locations,
            $this->processServices($draft, $result->services, $locations),
            $this->processStaff($draft, $result->staff, $locations),
            $this->processFaq($draft, $result->faq),
            $this->processPolicies($draft, $result->policies),
        );
    }

    /**
     * With exactly one location, "which location is this for" isn't a judgment call the
     * AI (or the user) needs to make — there is nowhere else it could be. Forcing the
     * association here, unconditionally and without requiring confirmation, matches how
     * the live dashboard already treats a single-location salon (BranchPicker auto-
     * assigns it, no picker shown at all — resources/js/Pages/Dashboard/Index.tsx) and
     * closes a real gap: the review screen's location-checklist field is itself hidden
     * whenever only one location is available (nothing to choose between), so a
     * service/staff row whose location_associations the AI still flagged
     * requires_confirmation had no on-screen control left to confirm it at all —
     * confirming the draft stayed permanently blocked with no way to resolve it.
     *
     * @param  list<ServiceData>|list<StaffData>  $items
     * @param  list<LocationData>  $locations
     * @return list<ServiceData>|list<StaffData>
     */
    private function resolveLocationAssociationsForSingleLocation(array $items, array $locations): array
    {
        if (count($locations) !== 1) {
            return $items;
        }

        $onlyLocationName = $locations[0]->name?->value;

        if (! is_string($onlyLocationName) || $onlyLocationName === '') {
            return $items;
        }

        $resolved = new ImportedFact(
            value: [$onlyLocationName],
            sourceUrl: null,
            confidenceScore: 1.0,
            requiresConfirmation: false,
        );

        return array_map(static fn ($item) => $item->with(locationAssociations: $resolved), $items);
    }

    /**
     * Sites that state their schedule once ("Program: L-V 9-18"), not next to a specific
     * address, get it extracted onto business.opening_hours rather than any location's
     * own opening_hours — legitimate on a single-storefront site, but also a plausible
     * default for a chain's individual branches when a page never spelled out per-branch
     * hours. Rather than silently guessing (risking a real per-branch difference going
     * unnoticed) or refusing to guess at all (leaving every location's opening_hours
     * step permanently unfinished until manually typed in), this fills the gap the same
     * way any other low-confidence guess in this pipeline is handled: applied, but
     * flagged requires_confirmation so the review screen surfaces it per location for the
     * user to accept or correct. Never touches a location that already has its own
     * scheduled hours.
     *
     * Runs inside processLocations(), before consolidateTemporaryLocationsByName()'s
     * city+hours fallback — not after, as a separate final step — because that fallback
     * needs to see the (possibly backfilled) hours to recognize otherwise-identical
     * nameless/addressless mentions as duplicates; run afterward, it would instead see
     * every such mention as having no hours yet, never match, and then have this method
     * silently hand each of them its own separate copy of the same schedule.
     *
     * array_map() preserves keys, so this accepts and returns either the plain list used
     * by callers outside this class or the fingerprint-keyed map used internally here.
     *
     * @template T of array<string, LocationData>|list<LocationData>
     *
     * @param  T  $locations
     * @return T
     */
    private function backfillLocationHoursFromBusiness(?BusinessProfileData $business, array $locations): array
    {
        $businessHours = $business?->openingHours;

        if ($businessHours === null || ! is_array($businessHours->value) || ! $this->hoursValidator->hasAnyScheduledDay($businessHours->value)) {
            return $locations;
        }

        return array_map(function (LocationData $location) use ($businessHours) {
            $ownHours = $location->openingHours;

            if ($ownHours !== null && is_array($ownHours->value) && $this->hoursValidator->hasAnyScheduledDay($ownHours->value)) {
                return $location;
            }

            return $location->with(openingHours: $businessHours->withRequiresConfirmation(true));
        }, $locations);
    }

    /**
     * @param  list<LocationData>  $locations
     * @return list<LocationData>
     */
    private function processLocations(OnboardingDraft $draft, array $locations, ?BusinessProfileData $business): array
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

        [$byFingerprint, $order] = $this->consolidateLocationsByAddressPrefix($byFingerprint, $order);
        [$byFingerprint, $order] = $this->consolidateLocationsByCanonicalAddressAcrossNames($byFingerprint, $order);
        $byFingerprint = $this->backfillLocationHoursFromBusiness($business, $byFingerprint);

        return $this->consolidateTemporaryLocationsByName($byFingerprint, $order);
    }

    /**
     * The pass above only compares addresses within locations that already share a
     * normalized name — by design, since a bare name alone is too weak a signal to merge
     * on across different premises (see consolidateTemporaryLocationsByName's docblock).
     * But a real import produced three entries for the identical premises under three
     * unrelated "names" — the real business name, the site's own URL (misread by the AI
     * as a location name), and a neighborhood reference — that never even got compared to
     * each other, because each landed in its own single-member name group first. When the
     * address signature (street name + house number, independent of word order and of
     * whether a "nr" marker was inserted — see LocationData::normalizedStreetNameKey()/
     * normalizedHouseNumberKey()) is shared by more than one entry, they're merged
     * regardless of name: an exact street+number match is a far stronger identity signal
     * than any name text. Any real name disagreement is not resolved silently —
     * ImportedFactMerger flags it as a conflict requiring confirmation, exactly like a
     * conflicting price or role, so the user still picks the correct name in review.
     *
     * @param  array<string, LocationData>  $byFingerprint
     * @param  list<string>  $order
     * @return array{0: array<string, LocationData>, 1: list<string>}
     */
    private function consolidateLocationsByCanonicalAddressAcrossNames(array $byFingerprint, array $order): array
    {
        $fingerprintsByAddress = [];

        foreach ($order as $fingerprint) {
            $location = $byFingerprint[$fingerprint];
            $streetKey = $location->normalizedStreetNameKey();
            $numberKey = $location->normalizedHouseNumberKey();

            if ($streetKey === '' || $numberKey === '') {
                continue;
            }

            $fingerprintsByAddress["{$streetKey}|{$numberKey}"][] = $fingerprint;
        }

        $dropped = [];

        foreach ($fingerprintsByAddress as $fingerprints) {
            if (count($fingerprints) < 2) {
                continue;
            }

            $target = $fingerprints[0];

            foreach (array_slice($fingerprints, 1) as $fingerprint) {
                $byFingerprint[$target] = $this->mergeLocations($byFingerprint[$target], $byFingerprint[$fingerprint]);
                $dropped[$fingerprint] = true;
            }
        }

        $keptOrder = array_values(array_filter($order, static fn (string $fp) => ! isset($dropped[$fp])));

        return [$byFingerprint, $keptOrder];
    }

    /**
     * Two AI calls sometimes describe the same premises with only partially-overlapping
     * contact details — "Str. X 1" vs "Str. X 1 B" (an entrance/suite letter one call
     * picked up and another didn't — still hashes differently even after address
     * normalization, deliberately: a bare letter difference can be a genuinely different
     * address, so stableFingerprint() doesn't collapse it on its own), or the contact page
     * captured the address while a booking-widget page only captured the phone number.
     * Either way stableFingerprint() (name + address + phone, all three in the hash) treats
     * them as different identities. Within locations sharing the same normalized name, this
     * merges any pair whose addresses aren't in conflict (equal, prefix-compatible, or one
     * side simply didn't capture an address) AND whose phones aren't in conflict (equal or
     * one side missing) — i.e. anywhere the two mentions could plausibly complete each
     * other rather than contradict. A pair that actually disagrees on a shared, non-empty
     * field (different address, different phone) is left alone.
     *
     * @param  array<string, LocationData>  $byFingerprint
     * @param  list<string>  $order
     * @return array{0: array<string, LocationData>, 1: list<string>}
     */
    private function consolidateLocationsByAddressPrefix(array $byFingerprint, array $order): array
    {
        $fingerprintsByName = [];

        foreach ($order as $fingerprint) {
            $nameKey = $byFingerprint[$fingerprint]->normalizedNameKey();

            if ($nameKey !== '') {
                $fingerprintsByName[$nameKey][] = $fingerprint;
            }
        }

        $dropped = [];

        foreach ($fingerprintsByName as $fingerprints) {
            foreach ($fingerprints as $i => $fpA) {
                if (isset($dropped[$fpA])) {
                    continue;
                }

                foreach (array_slice($fingerprints, $i + 1) as $fpB) {
                    if (isset($dropped[$fpB])) {
                        continue;
                    }

                    $a = $byFingerprint[$fpA];
                    $b = $byFingerprint[$fpB];

                    if (
                        $this->addressesAreCompatible($a->normalizedAddressKey(), $b->normalizedAddressKey())
                        && $this->valuesAreCompatible($a->normalizedPhoneKey(), $b->normalizedPhoneKey())
                    ) {
                        $byFingerprint[$fpA] = $this->mergeLocations($a, $b);
                        $dropped[$fpB] = true;
                    }
                }
            }
        }

        $keptOrder = array_values(array_filter($order, static fn (string $fp) => ! isset($dropped[$fp])));

        return [$byFingerprint, $keptOrder];
    }

    private function addressesAreCompatible(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return true;
        }

        if ($a === $b) {
            return true;
        }

        return str_starts_with($a, "{$b} ") || str_starts_with($b, "{$a} ");
    }

    /**
     * stableFingerprint() needs a name plus address/phone, so a page fragment that
     * mentions only a premises name (no address) or only an address (no name — e.g. a
     * dense page split into chunks where one chunk's slice of a table lost its row
     * header) always lands in the temporary-fingerprint branch above, even when a
     * different page already produced a full, stable entry for the same premises. This
     * pass folds such a partial entry into the one stable entry it unambiguously
     * matches — by name first, falling back to address alone when there's no usable
     * name — only when there's exactly one candidate; with zero or several it's left
     * alone rather than risk merging two different locations.
     *
     * @param  array<string, LocationData>  $byFingerprint
     * @param  list<string>  $order
     * @return list<LocationData>
     */
    private function consolidateTemporaryLocationsByName(array $byFingerprint, array $order): array
    {
        $stableFingerprintsByName = [];
        $stableFingerprintsByAddress = [];

        foreach ($order as $fingerprint) {
            $location = $byFingerprint[$fingerprint];

            if ($location->isTemporaryFingerprint) {
                continue;
            }

            $nameKey = $location->normalizedNameKey();

            if ($nameKey !== '') {
                $stableFingerprintsByName[$nameKey][] = $fingerprint;
            }

            $addressKey = $location->normalizedAddressKey();

            if ($addressKey !== '') {
                $stableFingerprintsByAddress[$addressKey][] = $fingerprint;
            }
        }

        $dropped = [];
        $temporaryTargetByAddress = [];
        $temporaryTargetByCityAndHours = [];

        foreach ($order as $fingerprint) {
            $location = $byFingerprint[$fingerprint];

            if (! $location->isTemporaryFingerprint) {
                continue;
            }

            $nameKey = $location->normalizedNameKey();
            $candidates = $nameKey !== '' ? ($stableFingerprintsByName[$nameKey] ?? []) : [];

            if (count($candidates) !== 1) {
                $addressKey = $location->normalizedAddressKey();
                $candidates = $addressKey !== '' ? ($stableFingerprintsByAddress[$addressKey] ?? []) : [];
            }

            if (count($candidates) === 1) {
                $target = $candidates[0];
                $byFingerprint[$target] = $this->mergeLocations($byFingerprint[$target], $location);
                $dropped[$fingerprint] = true;

                continue;
            }

            // No stable entry to fold into — still worth collapsing repeated nameless
            // mentions of the identical address into each other (e.g. a single-location
            // site whose address appears in the footer of every page, never paired with
            // a name). Mirrors consolidateServicesByName()'s temporary-to-temporary pass.
            $addressKey = $location->normalizedAddressKey();

            if ($addressKey !== '') {
                if (isset($temporaryTargetByAddress[$addressKey])) {
                    $target = $temporaryTargetByAddress[$addressKey];
                    $byFingerprint[$target] = $this->mergeLocations($byFingerprint[$target], $location);
                    $dropped[$fingerprint] = true;
                } else {
                    $temporaryTargetByAddress[$addressKey] = $fingerprint;
                }

                continue;
            }

            // No address either — a fragment with neither name nor street (e.g. just
            // "Bucuresti" plus a schedule, repeated on several pages). City alone is too
            // weak a signal on its own (a chain has several branches in the same city),
            // so this only groups fragments that also share the identical schedule —
            // real branches essentially never share both by coincidence.
            $cityKey = $location->normalizedCityKey();
            $hoursKey = $this->hoursSignature($location->openingHours?->value);

            if ($cityKey === '' || $hoursKey === '') {
                continue;
            }

            $cityAndHoursKey = "{$cityKey}|{$hoursKey}";

            if (isset($temporaryTargetByCityAndHours[$cityAndHoursKey])) {
                $target = $temporaryTargetByCityAndHours[$cityAndHoursKey];
                $byFingerprint[$target] = $this->mergeLocations($byFingerprint[$target], $location);
                $dropped[$fingerprint] = true;
            } else {
                $temporaryTargetByCityAndHours[$cityAndHoursKey] = $fingerprint;
            }
        }

        $keptOrder = array_values(array_filter($order, static fn (string $fp) => ! isset($dropped[$fp])));

        return array_map(static fn (string $fp) => $byFingerprint[$fp], $keptOrder);
    }

    /**
     * @param  list<ServiceData>  $services
     * @return list<ServiceData>
     */
    private function processServices(OnboardingDraft $draft, array $services, array $locations): array
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

        [$byFingerprint, $order] = $this->consolidateServicesByCompatibleDetails($byFingerprint, $order);
        $consolidated = $this->consolidateServicesByName($byFingerprint, $order);

        return $this->resolveLocationAssociationsForSingleLocation($consolidated, $locations);
    }

    /**
     * Two AI calls sometimes each capture a different slice of the same service's details
     * — a "servicii" page listing name+category with no duration, a "preturi" page listing
     * name+duration with no category — which still hash to different stable fingerprints
     * since stableFingerprint() hashes category/location/duration together. Within services
     * sharing the same normalized name, this merges any pair whose category, location
     * reference, and duration are pairwise non-conflicting (equal, or one side simply
     * didn't capture that field). A pair that actually disagrees on a shared, non-empty
     * field (different category, different duration) is left alone — see
     * test_services_with_different_category_or_location_stay_separate.
     *
     * @param  array<string, ServiceData>  $byFingerprint
     * @param  list<string>  $order
     * @return array{0: array<string, ServiceData>, 1: list<string>}
     */
    private function consolidateServicesByCompatibleDetails(array $byFingerprint, array $order): array
    {
        $fingerprintsByName = [];

        foreach ($order as $fingerprint) {
            $nameKey = $byFingerprint[$fingerprint]->normalizedNameKey();

            if ($nameKey !== '') {
                $fingerprintsByName[$nameKey][] = $fingerprint;
            }
        }

        $dropped = [];

        foreach ($fingerprintsByName as $fingerprints) {
            foreach ($fingerprints as $i => $fpA) {
                if (isset($dropped[$fpA])) {
                    continue;
                }

                foreach (array_slice($fingerprints, $i + 1) as $fpB) {
                    if (isset($dropped[$fpB])) {
                        continue;
                    }

                    $a = $byFingerprint[$fpA];
                    $b = $byFingerprint[$fpB];

                    if (
                        $this->valuesAreCompatible($a->normalizedCategoryKey(), $b->normalizedCategoryKey())
                        && $this->valuesAreCompatible($a->normalizedLocationKey(), $b->normalizedLocationKey())
                        && $this->valuesAreCompatible($a->normalizedDurationKey(), $b->normalizedDurationKey())
                    ) {
                        $byFingerprint[$fpA] = $this->mergeServices($a, $b);
                        $dropped[$fpB] = true;
                    }
                }
            }
        }

        $keptOrder = array_values(array_filter($order, static fn (string $fp) => ! isset($dropped[$fp])));

        return [$byFingerprint, $keptOrder];
    }

    private function valuesAreCompatible(string $a, string $b): bool
    {
        return $a === '' || $b === '' || $a === $b;
    }

    /**
     * A bare name-only mention (no category/price/duration/location — e.g. a shared nav
     * menu or footer "services" list repeated identically on every page) can't build a
     * stableFingerprint() on its own (see that method's docblock), so it always lands in
     * the temporary-fingerprint branch above, keyed by array index — meaning the exact
     * same mention on N different pages produced N separate rows in a real import ("
     * Terapie Laser pentru regenerarea parului" appeared 4 times, unchanged, sourced
     * from the homepage, contact, and two "despre" pages). Unlike locations, name alone
     * is a reliable enough match here — a service name repeated verbatim with zero other
     * distinguishing data is far more likely the same shared mention than two
     * coincidentally identically-named services. Folds those together: first into a
     * single stable entry sharing the same name when there's exactly one such candidate,
     * then merges any remaining bare mentions sharing a name into each other.
     *
     * @param  array<string, ServiceData>  $byFingerprint
     * @param  list<string>  $order
     * @return list<ServiceData>
     */
    private function consolidateServicesByName(array $byFingerprint, array $order): array
    {
        $stableFingerprintsByName = [];

        foreach ($order as $fingerprint) {
            $service = $byFingerprint[$fingerprint];

            if ($service->isTemporaryFingerprint) {
                continue;
            }

            $nameKey = $service->normalizedNameKey();

            if ($nameKey !== '') {
                $stableFingerprintsByName[$nameKey][] = $fingerprint;
            }
        }

        $dropped = [];
        $temporaryTargetByName = [];

        foreach ($order as $fingerprint) {
            $service = $byFingerprint[$fingerprint];

            if (! $service->isTemporaryFingerprint) {
                continue;
            }

            $nameKey = $service->normalizedNameKey();

            if ($nameKey === '') {
                continue;
            }

            $stableCandidates = $stableFingerprintsByName[$nameKey] ?? [];

            if (count($stableCandidates) === 1) {
                $target = $stableCandidates[0];
                $byFingerprint[$target] = $this->mergeServices($byFingerprint[$target], $service);
                $dropped[$fingerprint] = true;

                continue;
            }

            if (isset($temporaryTargetByName[$nameKey])) {
                $target = $temporaryTargetByName[$nameKey];
                $byFingerprint[$target] = $this->mergeServices($byFingerprint[$target], $service);
                $dropped[$fingerprint] = true;
            } else {
                $temporaryTargetByName[$nameKey] = $fingerprint;
            }
        }

        $keptOrder = array_values(array_filter($order, static fn (string $fp) => ! isset($dropped[$fp])));

        return array_map(static fn (string $fp) => $byFingerprint[$fp], $keptOrder);
    }

    /**
     * @param  list<StaffData>  $staff
     * @return list<StaffData>
     */
    private function processStaff(OnboardingDraft $draft, array $staff, array $locations): array
    {
        /** @var array<string, StaffData> $byFingerprint */
        $byFingerprint = [];
        $order = [];

        foreach ($staff as $index => $member) {
            $stable = $member->stableFingerprint();

            if ($stable === null) {
                $temporary = $this->temporaryFingerprint($draft, 'staff', $index, $member->name?->value);
                $member = $member->with(
                    name: $this->forceRequiresConfirmation($member->name),
                    fingerprint: $temporary,
                    isTemporaryFingerprint: true,
                );
                $byFingerprint[$temporary] = $member;
                $order[] = $temporary;

                continue;
            }

            if (isset($byFingerprint[$stable])) {
                $byFingerprint[$stable] = $this->mergeStaff($byFingerprint[$stable], $member->with(fingerprint: $stable));
            } else {
                $byFingerprint[$stable] = $member->with(fingerprint: $stable);
                $order[] = $stable;
            }
        }

        $result = array_map(static fn (string $fp) => $byFingerprint[$fp], $order);

        return $this->resolveLocationAssociationsForSingleLocation($result, $locations);
    }

    /**
     * @param  list<FaqEntryData>  $entries
     * @return list<FaqEntryData>
     */
    private function processFaq(OnboardingDraft $draft, array $entries): array
    {
        /** @var array<string, FaqEntryData> $byFingerprint */
        $byFingerprint = [];
        $order = [];

        foreach ($entries as $index => $entry) {
            $stable = $entry->stableFingerprint();

            if ($stable === null) {
                $temporary = $this->temporaryFingerprint($draft, 'faq', $index, $entry->question?->value);
                $entry = $entry->with(
                    question: $this->forceRequiresConfirmation($entry->question),
                    fingerprint: $temporary,
                    isTemporaryFingerprint: true,
                );
                $byFingerprint[$temporary] = $entry;
                $order[] = $temporary;

                continue;
            }

            if (isset($byFingerprint[$stable])) {
                $byFingerprint[$stable] = $this->mergeFaq($byFingerprint[$stable], $entry->with(fingerprint: $stable));
            } else {
                $byFingerprint[$stable] = $entry->with(fingerprint: $stable);
                $order[] = $stable;
            }
        }

        return array_map(static fn (string $fp) => $byFingerprint[$fp], $order);
    }

    /**
     * @param  list<PolicyEntryData>  $entries
     * @return list<PolicyEntryData>
     */
    private function processPolicies(OnboardingDraft $draft, array $entries): array
    {
        /** @var array<string, PolicyEntryData> $byFingerprint */
        $byFingerprint = [];
        $order = [];

        foreach ($entries as $index => $entry) {
            $stable = $entry->stableFingerprint();

            if ($stable === null) {
                $temporary = $this->temporaryFingerprint($draft, 'policy', $index, $entry->title?->value);
                $entry = $entry->with(
                    title: $this->forceRequiresConfirmation($entry->title),
                    fingerprint: $temporary,
                    isTemporaryFingerprint: true,
                );
                $byFingerprint[$temporary] = $entry;
                $order[] = $temporary;

                continue;
            }

            if (isset($byFingerprint[$stable])) {
                $byFingerprint[$stable] = $this->mergePolicy($byFingerprint[$stable], $entry->with(fingerprint: $stable));
            } else {
                $byFingerprint[$stable] = $entry->with(fingerprint: $stable);
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

    private function mergeStaff(StaffData $a, StaffData $b): StaffData
    {
        return $a->with(
            name: $this->mergeFact($a->name, $b->name),
            role: $this->mergeFact($a->role, $b->role),
            offeredServices: $this->mergeFact($a->offeredServices, $b->offeredServices),
            locationAssociations: $this->mergeFact($a->locationAssociations, $b->locationAssociations),
            sourceUrls: array_values(array_unique([...$a->sourceUrls, ...$b->sourceUrls])),
            externalId: $a->externalId ?? $b->externalId,
        );
    }

    private function mergeFaq(FaqEntryData $a, FaqEntryData $b): FaqEntryData
    {
        return $a->with(
            question: $this->mergeFact($a->question, $b->question),
            answer: $this->mergeFact($a->answer, $b->answer),
            sourceUrls: array_values(array_unique([...$a->sourceUrls, ...$b->sourceUrls])),
            externalId: $a->externalId ?? $b->externalId,
        );
    }

    private function mergePolicy(PolicyEntryData $a, PolicyEntryData $b): PolicyEntryData
    {
        return $a->with(
            title: $this->mergeFact($a->title, $b->title),
            content: $this->mergeFact($a->content, $b->content),
            sourceUrls: array_values(array_unique([...$a->sourceUrls, ...$b->sourceUrls])),
            externalId: $a->externalId ?? $b->externalId,
        );
    }

    private function mergeFact(?ImportedFact $a, ?ImportedFact $b): ?ImportedFact
    {
        return $this->factMerger->merge($a, $b);
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

    /**
     * A stable, order-independent signature for a day-keyed opening-hours array, used to
     * decide whether two nameless/addressless location fragments describe the same
     * schedule — see consolidateTemporaryLocationsByName()'s city+hours fallback.
     */
    private function hoursSignature(mixed $hours): string
    {
        if (! is_array($hours) || ! $this->hoursValidator->hasAnyScheduledDay($hours)) {
            return '';
        }

        ksort($hours);

        return implode('|', array_map(
            static fn ($day, $value) => "{$day}:".(is_string($value) ? $value : ''),
            array_keys($hours),
            $hours,
        ));
    }
}
