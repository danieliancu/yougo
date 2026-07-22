<?php

namespace Tests\Unit\Onboarding;

use App\DataTransferObjects\Onboarding\NormalizedExtractionResult;
use App\Enums\OnboardingDraftStatus;
use App\Models\OnboardingDraft;
use App\Models\Salon;
use App\Models\User;
use App\Services\Onboarding\OnboardingEntityDeduplicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingEntityDeduplicatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_merges_the_same_service_found_on_three_pages(): void
    {
        $draft = $this->createDraft();
        $deduplicator = new OnboardingEntityDeduplicator;

        $result = NormalizedExtractionResult::fromArray([
            'schema_version' => '1.0',
            'services' => [
                $this->service('Manichiura', 'unghii', 'https://example.ro/'),
                $this->service('Manichiura', 'unghii', 'https://example.ro/servicii'),
                $this->service('Manichiura', 'unghii', 'https://example.ro/preturi'),
            ],
        ]);

        $processed = $deduplicator->process($draft, $result);

        $this->assertCount(1, $processed->services);
        $this->assertEqualsCanonicalizing(
            ['https://example.ro/', 'https://example.ro/servicii', 'https://example.ro/preturi'],
            $processed->services[0]->sourceUrls
        );
    }

    public function test_merges_bare_name_only_service_mentions_repeated_across_pages(): void
    {
        // A real import kept "Terapie Laser pentru regenerarea parului" as 4 separate
        // service rows: the exact same bare mention (no category/price/duration —
        // stableFingerprint() can't build an identity from a name alone, see that
        // method's docblock) showed up verbatim on the homepage, contact page, and two
        // "despre" pages — almost certainly one shared nav/footer services list, not 4
        // distinct services. Each landed in the temporary-fingerprint bucket keyed by
        // array index, so they never merged on their own.
        $draft = $this->createDraft();
        $deduplicator = new OnboardingEntityDeduplicator;

        $result = NormalizedExtractionResult::fromArray([
            'schema_version' => '1.0',
            'services' => [
                $this->service('Terapie Laser pentru regenerarea parului', '', 'https://example.ro/'),
                $this->service('Terapie Laser pentru regenerarea parului', '', 'https://example.ro/contact'),
                $this->service('terapie laser pentru regenerarea parului', '', 'https://example.ro/despre'),
            ],
        ]);

        $processed = $deduplicator->process($draft, $result);

        $this->assertCount(1, $processed->services);
        $this->assertEqualsCanonicalizing(
            ['https://example.ro/', 'https://example.ro/contact', 'https://example.ro/despre'],
            $processed->services[0]->sourceUrls
        );
    }

    public function test_bare_name_only_service_mention_merges_into_the_one_matching_full_entry(): void
    {
        // The other half of the same fix: a bare mention should fold into an existing
        // fuller (stable) entry sharing its name, not just into other bare mentions.
        $draft = $this->createDraft();
        $deduplicator = new OnboardingEntityDeduplicator;

        $result = NormalizedExtractionResult::fromArray([
            'schema_version' => '1.0',
            'services' => [
                $this->service('Manichiura', 'unghii', 'https://example.ro/servicii', price: '80'),
                $this->service('Manichiura', '', 'https://example.ro/'),
            ],
        ]);

        $processed = $deduplicator->process($draft, $result);

        $this->assertCount(1, $processed->services);
        $this->assertSame('80', $processed->services[0]->price?->value);
        $this->assertEqualsCanonicalizing(
            ['https://example.ro/servicii', 'https://example.ro/'],
            $processed->services[0]->sourceUrls
        );
    }

    public function test_conflicting_prices_are_kept_as_a_conflict_and_force_confirmation(): void
    {
        $draft = $this->createDraft();
        $deduplicator = new OnboardingEntityDeduplicator;

        $result = NormalizedExtractionResult::fromArray([
            'schema_version' => '1.0',
            'services' => [
                $this->service('Manichiura', 'unghii', 'https://example.ro/', price: '100'),
                $this->service('Manichiura', 'unghii', 'https://example.ro/preturi', price: '120'),
            ],
        ]);

        $processed = $deduplicator->process($draft, $result);

        $this->assertCount(1, $processed->services);
        $price = $processed->services[0]->price;
        $this->assertNotNull($price);
        $this->assertTrue($price->requiresConfirmation);
        $this->assertCount(1, $price->conflicts);
    }

    public function test_services_with_different_category_or_location_stay_separate(): void
    {
        $draft = $this->createDraft();
        $deduplicator = new OnboardingEntityDeduplicator;

        $result = NormalizedExtractionResult::fromArray([
            'schema_version' => '1.0',
            'services' => [
                $this->service('Manichiura', 'unghii'),
                $this->service('Manichiura', 'spa'),
            ],
        ]);

        $processed = $deduplicator->process($draft, $result);

        $this->assertCount(2, $processed->services);
    }

    public function test_service_with_category_only_merges_with_same_named_service_with_duration_only(): void
    {
        // A "servicii" page lists name+category with no duration; a "preturi" page lists
        // the same service by name+duration with no category. Both are individually
        // stable (stableFingerprint() only needs one of category/location/duration), but
        // they hash differently because that one field differs — without this pass they'd
        // stay two separate services.
        $draft = $this->createDraft();
        $deduplicator = new OnboardingEntityDeduplicator;

        $result = NormalizedExtractionResult::fromArray([
            'schema_version' => '1.0',
            'services' => [
                [
                    'name' => ['value' => 'Manichiura', 'confidence_score' => 0.9, 'requires_confirmation' => false],
                    'category' => ['value' => 'Unghii', 'confidence_score' => 0.9, 'requires_confirmation' => false],
                    'source_urls' => ['https://example.ro/servicii'],
                ],
                [
                    'name' => ['value' => 'Manichiura', 'confidence_score' => 0.9, 'requires_confirmation' => false],
                    'duration' => ['value' => 30, 'confidence_score' => 0.9, 'requires_confirmation' => false],
                    'source_urls' => ['https://example.ro/preturi'],
                ],
            ],
        ]);

        $processed = $deduplicator->process($draft, $result);

        $this->assertCount(1, $processed->services);
        $this->assertSame('Unghii', $processed->services[0]->category?->value);
        $this->assertSame(30, $processed->services[0]->duration?->value);
    }

    public function test_locations_with_different_addresses_stay_separate(): void
    {
        $draft = $this->createDraft();
        $deduplicator = new OnboardingEntityDeduplicator;

        $result = NormalizedExtractionResult::fromArray([
            'schema_version' => '1.0',
            'locations' => [
                $this->location('Sediul Principal', 'Str. Exemplu 1'),
                $this->location('Sediul Principal', 'Str. Exemplu 2'),
            ],
        ]);

        $processed = $deduplicator->process($draft, $result);

        $this->assertCount(2, $processed->locations);
    }

    public function test_location_with_address_only_merges_with_same_named_location_with_phone_only(): void
    {
        // The contact page captured name+address with no phone; the booking-widget page
        // captured the same premises by name+phone with no address. Both are individually
        // stable (stableFingerprint() only needs one of address/phone), but they hash
        // differently because that one field differs — without this pass they'd stay two
        // separate locations.
        $draft = $this->createDraft();
        $deduplicator = new OnboardingEntityDeduplicator;

        $result = NormalizedExtractionResult::fromArray([
            'schema_version' => '1.0',
            'locations' => [
                [
                    'name' => ['value' => 'Salonul Ghencea', 'confidence_score' => 0.9, 'requires_confirmation' => false],
                    'address' => ['value' => 'Str. Exemplu 1', 'confidence_score' => 0.9, 'requires_confirmation' => false],
                    'source_urls' => ['https://example.ro/contact'],
                ],
                [
                    'name' => ['value' => 'Salonul Ghencea', 'confidence_score' => 0.9, 'requires_confirmation' => false],
                    'phone' => ['value' => '0721111111', 'confidence_score' => 0.9, 'requires_confirmation' => false],
                    'source_urls' => ['https://example.ro/programari'],
                ],
            ],
        ]);

        $processed = $deduplicator->process($draft, $result);

        $this->assertCount(1, $processed->locations);
        $this->assertSame('Str. Exemplu 1', $processed->locations[0]->address?->value);
        $this->assertSame('0721111111', $processed->locations[0]->phone?->value);
    }

    public function test_same_location_described_inconsistently_across_ai_calls_merges_into_one(): void
    {
        $draft = $this->createDraft();
        $deduplicator = new OnboardingEntityDeduplicator;

        $result = NormalizedExtractionResult::fromArray([
            'schema_version' => '1.0',
            'locations' => [
                // Name variant: "Salonul" (definite article) vs "Salon".
                $this->location('Salonul Ghencea', 'Strada Constantin Titel Petrescu 1'),
                // Address variant: "Str." abbreviation plus a suite letter one call picked up.
                $this->location('Salon Ghencea', 'Str. Constantin Titel Petrescu 1 B'),
                // Name-only mention (no address in that page fragment) — temporary fingerprint.
                ['name' => ['value' => 'Salon Ghencea', 'confidence_score' => 0.5, 'requires_confirmation' => false], 'source_urls' => ['https://example.ro/']],
            ],
        ]);

        $processed = $deduplicator->process($draft, $result);

        $this->assertCount(1, $processed->locations);
        $this->assertFalse($processed->locations[0]->isTemporaryFingerprint);
    }

    public function test_a_nameless_address_only_mention_merges_by_address_alone(): void
    {
        $draft = $this->createDraft();
        $deduplicator = new OnboardingEntityDeduplicator;

        $result = NormalizedExtractionResult::fromArray([
            'schema_version' => '1.0',
            'locations' => [
                $this->location('Salonul Ghencea', 'Strada Constantin Titel Petrescu 1'),
                // A chunk that mentions the address with no premises name at all —
                // stableFingerprint() can't identify it (name is required), so it must
                // fall back to matching the one stable location with that address.
                [
                    'address' => ['value' => 'Strada Constantin Titel Petrescu 1', 'confidence_score' => 0.7, 'requires_confirmation' => false],
                    'source_urls' => ['https://example.ro/'],
                ],
            ],
        ]);

        $processed = $deduplicator->process($draft, $result);

        $this->assertCount(1, $processed->locations);
        $this->assertFalse($processed->locations[0]->isTemporaryFingerprint);
    }

    public function test_locations_with_wildly_different_names_merge_by_canonical_street_and_number(): void
    {
        // A real import: the same premises extracted three times under three unrelated
        // "names" — the real business name, the site's own URL (misread by the AI as a
        // location name), and a neighborhood reference — each with the address written
        // differently (number after the street, number before it, "nr." glued to the
        // number with no space). None of these share a normalized name, so the
        // same-name-group address-prefix pass never even compares them to each other.
        $draft = $this->createDraft();
        $deduplicator = new OnboardingEntityDeduplicator;

        $result = NormalizedExtractionResult::fromArray([
            'schema_version' => '1.0',
            'locations' => [
                [
                    'name' => ['value' => 'Beauty DAY', 'confidence_score' => 0.9, 'requires_confirmation' => false],
                    'address' => ['value' => 'Strada Miron Costin 20, București 011098, România', 'confidence_score' => 0.9, 'requires_confirmation' => false],
                    'source_urls' => ['https://beauty-day.ro/'],
                ],
                [
                    'name' => ['value' => 'www.beauty-day.ro', 'confidence_score' => 0.6, 'requires_confirmation' => false],
                    'address' => ['value' => '20 Strada Miron Costin', 'confidence_score' => 0.6, 'requires_confirmation' => false],
                    'source_urls' => ['https://beauty-day.ro/contact'],
                ],
                [
                    'name' => ['value' => 'Titulescu', 'confidence_score' => 0.5, 'requires_confirmation' => false],
                    'address' => ['value' => 'Strada Miron Costin nr.20', 'confidence_score' => 0.5, 'requires_confirmation' => false],
                    'source_urls' => ['https://beauty-day.ro/despre'],
                ],
                [
                    // The exact real-world edge case that broke an earlier version of this
                    // fix: a comma used as the address's OWN internal separator, sitting
                    // directly before the "nr." marker — naively truncating at the first
                    // comma (to drop a trailing city/postcode tail) would throw the house
                    // number away with it.
                    'address' => ['value' => 'Strada Miron Costin, nr. 20, sector 1', 'confidence_score' => 0.5, 'requires_confirmation' => false],
                    'source_urls' => ['https://beauty-day.ro/contact-2'],
                ],
            ],
        ]);

        $processed = $deduplicator->process($draft, $result);

        $this->assertCount(1, $processed->locations);
        $location = $processed->locations[0];
        // The disagreeing names are not silently resolved — flagged as a conflict
        // requiring confirmation, same as any other genuinely disagreeing field.
        $this->assertCount(2, $location->name->conflicts);
        $this->assertTrue($location->name->requiresConfirmation);
        $this->assertEqualsCanonicalizing(
            ['https://beauty-day.ro/', 'https://beauty-day.ro/contact', 'https://beauty-day.ro/despre', 'https://beauty-day.ro/contact-2'],
            $location->sourceUrls
        );
    }

    public function test_locations_at_genuinely_different_house_numbers_stay_separate(): void
    {
        $draft = $this->createDraft();
        $deduplicator = new OnboardingEntityDeduplicator;

        $result = NormalizedExtractionResult::fromArray([
            'schema_version' => '1.0',
            'locations' => [
                [
                    'name' => ['value' => 'Sediul Unu', 'confidence_score' => 0.9, 'requires_confirmation' => false],
                    'address' => ['value' => 'Strada Miron Costin 20', 'confidence_score' => 0.9, 'requires_confirmation' => false],
                    'source_urls' => ['https://example.ro/'],
                ],
                [
                    'name' => ['value' => 'Sediul Doi', 'confidence_score' => 0.9, 'requires_confirmation' => false],
                    'address' => ['value' => 'Strada Miron Costin 22', 'confidence_score' => 0.9, 'requires_confirmation' => false],
                    'source_urls' => ['https://example.ro/'],
                ],
            ],
        ]);

        $processed = $deduplicator->process($draft, $result);

        $this->assertCount(2, $processed->locations);
    }

    public function test_nameless_apartment_block_addresses_merge_despite_boulevard_abbreviation_and_extra_trailing_details(): void
    {
        // A real import: the same apartment-based business address, mentioned twice with
        // neither mention ever getting a name. Two compounding format differences: the
        // street-type abbreviation ("Bulevardul" vs "B-dul", not just the "Strada"/"Str."
        // case already handled) and one mention trailing off with extra sub-address
        // details ("interfon 32") the other never captured — both must resolve to the
        // same street+number signature for the nameless address-only merge pass to fire.
        $draft = $this->createDraft();
        $deduplicator = new OnboardingEntityDeduplicator;

        $result = NormalizedExtractionResult::fromArray([
            'schema_version' => '1.0',
            'locations' => [
                [
                    'address' => ['value' => 'Bulevardul Unirii, nr 27, bl 15, sc.2, etaj 3, ap 32', 'confidence_score' => 0.6, 'requires_confirmation' => false],
                    'source_urls' => ['https://example.ro/a'],
                ],
                [
                    'address' => ['value' => 'B-dul Unirii, nr 27, bloc 15, sc. 2, etaj 3, ap 32, interfon 32', 'confidence_score' => 0.6, 'requires_confirmation' => false],
                    'source_urls' => ['https://example.ro/b'],
                ],
            ],
        ]);

        $processed = $deduplicator->process($draft, $result);

        $this->assertCount(1, $processed->locations);
    }

    public function test_nameless_addressless_mentions_merge_by_city_and_identical_hours(): void
    {
        // Neither a name nor a street — only a city and a schedule, repeated on several
        // pages (e.g. a generic "contact us" blurb). Too weak to merge on city alone (a
        // chain could have several branches in the same city), so this only fires when
        // the schedule also matches exactly.
        $draft = $this->createDraft();
        $deduplicator = new OnboardingEntityDeduplicator;

        $mention = [
            'city' => ['value' => 'Bucuresti', 'confidence_score' => 0.6, 'requires_confirmation' => false],
            'opening_hours' => ['value' => ['mon' => '10:00 - 21:00', 'sun' => 'Inchis'], 'confidence_score' => 0.6, 'requires_confirmation' => false],
            'source_urls' => ['https://example.ro/'],
        ];

        $result = NormalizedExtractionResult::fromArray([
            'schema_version' => '1.0',
            'locations' => [$mention, $mention, $mention],
        ]);

        $processed = $deduplicator->process($draft, $result);

        $this->assertCount(1, $processed->locations);
        $this->assertTrue($processed->locations[0]->isTemporaryFingerprint);
    }

    public function test_entirely_bare_city_only_mentions_merge_by_city_alone(): void
    {
        // A city and absolutely nothing else — no name, no address, no phone, no hours.
        // Real-world case: a site-wide city picker / service-area list ("Bucuresti",
        // "Cluj-Napoca", "Oradea", ...) repeated across many pages, which the analyzer
        // has no way to distinguish from real branch mentions. Unlike the city+hours
        // case above, there's no stronger signal being ignored here — merge by city.
        $draft = $this->createDraft();
        $deduplicator = new OnboardingEntityDeduplicator;

        $bucharest = [
            'city' => ['value' => 'Bucuresti', 'confidence_score' => 0.4, 'requires_confirmation' => true],
            'source_urls' => ['https://example.ro/'],
        ];
        $cluj = [
            'city' => ['value' => 'Cluj-Napoca', 'confidence_score' => 0.4, 'requires_confirmation' => true],
            'source_urls' => ['https://example.ro/'],
        ];

        $result = NormalizedExtractionResult::fromArray([
            'schema_version' => '1.0',
            'locations' => [$bucharest, $bucharest, $bucharest, $cluj, $cluj],
        ]);

        $processed = $deduplicator->process($draft, $result);

        $this->assertCount(2, $processed->locations);
    }

    public function test_nameless_addressless_mentions_merge_by_city_even_when_hours_only_come_from_business_backfill(): void
    {
        // Regression: the city+hours fallback above only works when hours are already
        // present at comparison time. When these fragments have no opening_hours of
        // their own — the far more common real shape, since a fragment with neither name
        // nor street rarely has its own schedule either — they only become identical
        // *after* backfillLocationHoursFromBusiness() copies the business-level schedule
        // into each. Running that backfill after this consolidation pass instead of
        // before it meant every fragment saw itself as having no hours, never matched
        // anything, and then independently received its own separate copy of the same
        // schedule — turning what should collapse to one location into N.
        $draft = $this->createDraft();
        $deduplicator = new OnboardingEntityDeduplicator;

        $mention = [
            'city' => ['value' => 'Bucuresti', 'confidence_score' => 0.6, 'requires_confirmation' => false],
            'source_urls' => ['https://example.ro/'],
        ];

        $result = NormalizedExtractionResult::fromArray([
            'schema_version' => '1.0',
            'business' => [
                'opening_hours' => ['value' => ['mon' => '10:00 - 21:00', 'sun' => 'Inchis'], 'confidence_score' => 0.9, 'requires_confirmation' => false],
            ],
            'locations' => [$mention, $mention, $mention],
        ]);

        $processed = $deduplicator->process($draft, $result);

        $this->assertCount(1, $processed->locations);
        $this->assertSame(['mon' => '10:00 - 21:00', 'sun' => 'Inchis'], $processed->locations[0]->openingHours?->value);
    }

    public function test_nameless_addressless_mentions_with_different_hours_stay_separate(): void
    {
        $draft = $this->createDraft();
        $deduplicator = new OnboardingEntityDeduplicator;

        $result = NormalizedExtractionResult::fromArray([
            'schema_version' => '1.0',
            'locations' => [
                [
                    'city' => ['value' => 'Bucuresti', 'confidence_score' => 0.6, 'requires_confirmation' => false],
                    'opening_hours' => ['value' => ['mon' => '10:00 - 21:00'], 'confidence_score' => 0.6, 'requires_confirmation' => false],
                    'source_urls' => ['https://example.ro/a'],
                ],
                [
                    'city' => ['value' => 'Bucuresti', 'confidence_score' => 0.6, 'requires_confirmation' => false],
                    'opening_hours' => ['value' => ['mon' => '08:00 - 16:00'], 'confidence_score' => 0.6, 'requires_confirmation' => false],
                    'source_urls' => ['https://example.ro/b'],
                ],
            ],
        ]);

        $processed = $deduplicator->process($draft, $result);

        $this->assertCount(2, $processed->locations);
    }

    public function test_repeated_nameless_mentions_of_the_same_address_merge_into_one(): void
    {
        $draft = $this->createDraft();
        $deduplicator = new OnboardingEntityDeduplicator;

        // A single-location site whose footer/contact address appears on several pages
        // with no premises name attached anywhere — every mention is temporary (no stable
        // fingerprint), so there's no stable entry to fold into; they must merge with
        // each other by address instead.
        $mention = [
            'address' => ['value' => 'Str. Racari, nr. 5, corp 51B, parter', 'confidence_score' => 0.7, 'requires_confirmation' => false],
            'source_urls' => ['https://example.ro/'],
        ];

        $result = NormalizedExtractionResult::fromArray([
            'schema_version' => '1.0',
            'locations' => [$mention, $mention, $mention],
        ]);

        $processed = $deduplicator->process($draft, $result);

        $this->assertCount(1, $processed->locations);
        $this->assertTrue($processed->locations[0]->isTemporaryFingerprint);
    }

    public function test_locations_merge_despite_disagreeing_county(): void
    {
        $draft = $this->createDraft();
        $deduplicator = new OnboardingEntityDeduplicator;

        $result = NormalizedExtractionResult::fromArray([
            'schema_version' => '1.0',
            'locations' => [
                $this->location('Sediul Principal', 'Str. Exemplu 1'),
                [
                    'name' => ['value' => 'Sediul Principal', 'confidence_score' => 0.9, 'requires_confirmation' => false],
                    'address' => ['value' => 'Str. Exemplu 1', 'confidence_score' => 0.9, 'requires_confirmation' => false],
                    'county' => ['value' => 'Sector 1', 'confidence_score' => 0.9, 'requires_confirmation' => false],
                    'source_urls' => ['https://example.ro/'],
                ],
            ],
        ]);

        $processed = $deduplicator->process($draft, $result);

        $this->assertCount(1, $processed->locations);
    }

    public function test_business_level_hours_backfill_into_a_locations_own_hours_flagged_for_confirmation(): void
    {
        // A site that states its schedule once ("Program: L-V 9-18"), not next to a
        // specific address, gets it extracted onto business.opening_hours rather than the
        // location's own opening_hours. Applied as a best-effort default so the location
        // isn't left with no schedule at all, but flagged requires_confirmation so the
        // review screen surfaces it for the user to accept or correct per location.
        $draft = $this->createDraft();
        $deduplicator = new OnboardingEntityDeduplicator;

        $result = NormalizedExtractionResult::fromArray([
            'schema_version' => '1.0',
            'business' => [
                'opening_hours' => ['value' => ['mon' => '09:00 - 18:00'], 'confidence_score' => 0.9, 'requires_confirmation' => false],
            ],
            'locations' => [
                $this->location('Central', 'Str. Exemplu 1'),
            ],
        ]);

        $processed = $deduplicator->process($draft, $result);

        $this->assertCount(1, $processed->locations);
        $this->assertSame(['mon' => '09:00 - 18:00'], $processed->locations[0]->openingHours?->value);
        $this->assertTrue($processed->locations[0]->openingHours?->requiresConfirmation);
    }

    public function test_business_level_hours_backfill_applies_to_every_location_missing_its_own_hours(): void
    {
        // Deliberately not limited to the single-location case — a chain whose site only
        // ever states one generic schedule gets it applied to every branch missing its
        // own, each flagged for confirmation so a branch with genuinely different hours
        // can be corrected individually rather than silently inheriting the wrong one.
        $draft = $this->createDraft();
        $deduplicator = new OnboardingEntityDeduplicator;

        $result = NormalizedExtractionResult::fromArray([
            'schema_version' => '1.0',
            'business' => [
                'opening_hours' => ['value' => ['mon' => '09:00 - 18:00'], 'confidence_score' => 0.9, 'requires_confirmation' => false],
            ],
            'locations' => [
                $this->location('Sediul Unu', 'Str. Exemplu 1'),
                $this->location('Sediul Doi', 'Str. Exemplu 2'),
            ],
        ]);

        $processed = $deduplicator->process($draft, $result);

        $this->assertCount(2, $processed->locations);
        foreach ($processed->locations as $location) {
            $this->assertSame(['mon' => '09:00 - 18:00'], $location->openingHours?->value);
            $this->assertTrue($location->openingHours?->requiresConfirmation);
        }
    }

    public function test_business_level_hours_backfill_never_touches_a_location_with_its_own_hours(): void
    {
        $draft = $this->createDraft();
        $deduplicator = new OnboardingEntityDeduplicator;

        $result = NormalizedExtractionResult::fromArray([
            'schema_version' => '1.0',
            'business' => [
                'opening_hours' => ['value' => ['mon' => '09:00 - 18:00'], 'confidence_score' => 0.9, 'requires_confirmation' => false],
            ],
            'locations' => [
                [
                    'name' => ['value' => 'Sediul Propriu', 'confidence_score' => 0.9, 'requires_confirmation' => false],
                    'address' => ['value' => 'Str. Exemplu 1', 'confidence_score' => 0.9, 'requires_confirmation' => false],
                    'opening_hours' => ['value' => ['tue' => '10:00 - 20:00'], 'confidence_score' => 0.9, 'requires_confirmation' => false],
                    'source_urls' => ['https://example.ro/'],
                ],
            ],
        ]);

        $processed = $deduplicator->process($draft, $result);

        $this->assertCount(1, $processed->locations);
        $this->assertSame(['tue' => '10:00 - 20:00'], $processed->locations[0]->openingHours?->value);
        $this->assertFalse($processed->locations[0]->openingHours?->requiresConfirmation);
    }

    public function test_no_business_level_hours_means_no_backfill(): void
    {
        $draft = $this->createDraft();
        $deduplicator = new OnboardingEntityDeduplicator;

        $result = NormalizedExtractionResult::fromArray([
            'schema_version' => '1.0',
            'locations' => [
                $this->location('Central', 'Str. Exemplu 1'),
            ],
        ]);

        $processed = $deduplicator->process($draft, $result);

        $this->assertCount(1, $processed->locations);
        $this->assertNull($processed->locations[0]->openingHours);
    }

    public function test_entity_with_insufficient_data_gets_a_temporary_fingerprint_requiring_confirmation(): void
    {
        $draft = $this->createDraft();
        $deduplicator = new OnboardingEntityDeduplicator;

        $result = NormalizedExtractionResult::fromArray([
            'schema_version' => '1.0',
            'locations' => [
                ['name' => ['value' => 'Sediul', 'confidence_score' => 0.4, 'requires_confirmation' => false]],
            ],
        ]);

        $processed = $deduplicator->process($draft, $result);

        $this->assertCount(1, $processed->locations);
        $this->assertTrue($processed->locations[0]->isTemporaryFingerprint);
        $this->assertTrue($processed->locations[0]->name->requiresConfirmation);
    }

    public function test_same_input_in_same_draft_produces_same_temporary_fingerprint(): void
    {
        $draft = $this->createDraft();
        $deduplicator = new OnboardingEntityDeduplicator;

        $raw = [
            'schema_version' => '1.0',
            'locations' => [
                ['name' => ['value' => 'Sediul', 'confidence_score' => 0.4, 'requires_confirmation' => false]],
            ],
        ];

        $first = $deduplicator->process($draft, NormalizedExtractionResult::fromArray($raw));
        $second = $deduplicator->process($draft, NormalizedExtractionResult::fromArray($raw));

        $this->assertSame($first->locations[0]->fingerprint, $second->locations[0]->fingerprint);
    }

    public function test_same_input_in_different_drafts_produces_different_temporary_fingerprints(): void
    {
        $draftA = $this->createDraft();
        $draftB = $this->createDraft();
        $deduplicator = new OnboardingEntityDeduplicator;

        $raw = [
            'schema_version' => '1.0',
            'locations' => [
                ['name' => ['value' => 'Sediul', 'confidence_score' => 0.4, 'requires_confirmation' => false]],
            ],
        ];

        $resultA = $deduplicator->process($draftA, NormalizedExtractionResult::fromArray($raw));
        $resultB = $deduplicator->process($draftB, NormalizedExtractionResult::fromArray($raw));

        $this->assertNotSame($resultA->locations[0]->fingerprint, $resultB->locations[0]->fingerprint);
    }

    public function test_stable_fingerprint_does_not_depend_on_source_url(): void
    {
        $draft = $this->createDraft();
        $deduplicator = new OnboardingEntityDeduplicator;

        $resultA = $deduplicator->process($draft, NormalizedExtractionResult::fromArray([
            'schema_version' => '1.0',
            'services' => [$this->service('Manichiura', 'unghii', 'https://example.ro/a')],
        ]));

        $resultB = $deduplicator->process($draft, NormalizedExtractionResult::fromArray([
            'schema_version' => '1.0',
            'services' => [$this->service('Manichiura', 'unghii', 'https://example.ro/b')],
        ]));

        $this->assertSame($resultA->services[0]->fingerprint, $resultB->services[0]->fingerprint);
    }

    public function test_single_location_forces_service_location_association_without_confirmation(): void
    {
        // Real bug: with one named location, the review screen hides "Locatii asociate"
        // entirely (nothing to choose between). A service whose location_associations the
        // AI still flagged requires_confirmation then had no on-screen control left to
        // confirm it — the draft could never be confirmed no matter what the user did on
        // the visible fields. With exactly one location there's no real decision left to
        // make, so this is resolved outright rather than left dangling.
        $draft = $this->createDraft();
        $deduplicator = new OnboardingEntityDeduplicator;

        $result = NormalizedExtractionResult::fromArray([
            'schema_version' => '1.0',
            'locations' => [
                $this->location('Beauty DAY', 'Strada Miron Costin 20'),
            ],
            'services' => [
                [
                    'name' => ['value' => 'Manichiura', 'confidence_score' => 0.9, 'requires_confirmation' => false],
                    'category' => ['value' => 'unghii', 'confidence_score' => 0.9, 'requires_confirmation' => false],
                    'location_associations' => ['value' => ['sector 1'], 'confidence_score' => 0.4, 'requires_confirmation' => true],
                    'source_urls' => ['https://example.ro/'],
                ],
            ],
        ]);

        $processed = $deduplicator->process($draft, $result);

        $this->assertCount(1, $processed->services);
        $service = $processed->services[0];
        $this->assertSame(['Beauty DAY'], $service->locationAssociations?->value);
        $this->assertFalse($service->locationAssociations?->requiresConfirmation);
    }

    public function test_multiple_locations_leave_service_location_association_untouched(): void
    {
        $draft = $this->createDraft();
        $deduplicator = new OnboardingEntityDeduplicator;

        $result = NormalizedExtractionResult::fromArray([
            'schema_version' => '1.0',
            'locations' => [
                $this->location('Sediul Unu', 'Str. Exemplu 1'),
                $this->location('Sediul Doi', 'Str. Exemplu 2'),
            ],
            'services' => [
                [
                    'name' => ['value' => 'Manichiura', 'confidence_score' => 0.9, 'requires_confirmation' => false],
                    'category' => ['value' => 'unghii', 'confidence_score' => 0.9, 'requires_confirmation' => false],
                    'location_associations' => ['value' => ['Sediul Unu'], 'confidence_score' => 0.4, 'requires_confirmation' => true],
                    'source_urls' => ['https://example.ro/'],
                ],
            ],
        ]);

        $processed = $deduplicator->process($draft, $result);

        $this->assertSame(['Sediul Unu'], $processed->services[0]->locationAssociations?->value);
        $this->assertTrue($processed->services[0]->locationAssociations?->requiresConfirmation);
    }

    public function test_merges_the_same_staff_member_found_on_two_pages(): void
    {
        $draft = $this->createDraft();
        $deduplicator = new OnboardingEntityDeduplicator;

        $result = NormalizedExtractionResult::fromArray([
            'schema_version' => '1.0',
            'staff' => [
                $this->staff('Maria Popescu', 'Coafeza', 'https://example.ro/'),
                $this->staff('Maria Popescu', 'Coafeza', 'https://example.ro/echipa'),
            ],
        ]);

        $processed = $deduplicator->process($draft, $result);

        $this->assertCount(1, $processed->staff);
        $this->assertEqualsCanonicalizing(
            ['https://example.ro/', 'https://example.ro/echipa'],
            $processed->staff[0]->sourceUrls
        );
    }

    public function test_merges_the_same_staff_member_when_role_text_differs_across_pages(): void
    {
        // A real import kept "Denisa Hazaparu" as 5 separate staff entries: dense-page
        // splitting fed the AI overlapping chunks of the same page, some yielding just
        // the name, others the name with a shorter or longer role string — and role used
        // to be part of the identity fingerprint, so every wording variant became a
        // "different" person. Name is the only reliable identity signal here; a role
        // text mismatch is a conflict to surface, not grounds for a duplicate row.
        $draft = $this->createDraft();
        $deduplicator = new OnboardingEntityDeduplicator;

        $result = NormalizedExtractionResult::fromArray([
            'schema_version' => '1.0',
            'staff' => [
                $this->staff('Denisa Hazaparu', '', 'https://example.ro/echipa'),
                $this->staff('Denisa Hazaparu', 'Coafor', 'https://example.ro/echipa'),
                $this->staff('DENISA HAZAPARU', 'Founder', 'https://example.ro/despre'),
                $this->staff('DENISA HAZAPARU', 'Founder, Trainer, Master Colorist', 'https://example.ro/despre'),
            ],
        ]);

        $processed = $deduplicator->process($draft, $result);

        $this->assertCount(1, $processed->staff);
        $this->assertTrue($processed->staff[0]->role->requiresConfirmation);
        $this->assertNotEmpty($processed->staff[0]->role->conflicts);
    }

    public function test_merges_the_same_faq_entry_found_on_two_pages(): void
    {
        $draft = $this->createDraft();
        $deduplicator = new OnboardingEntityDeduplicator;

        $result = NormalizedExtractionResult::fromArray([
            'schema_version' => '1.0',
            'faq' => [
                $this->faq('Ce program aveti?', 'https://example.ro/'),
                $this->faq('Ce program aveti?', 'https://example.ro/faq'),
            ],
        ]);

        $processed = $deduplicator->process($draft, $result);

        $this->assertCount(1, $processed->faq);
        $this->assertEqualsCanonicalizing(
            ['https://example.ro/', 'https://example.ro/faq'],
            $processed->faq[0]->sourceUrls
        );
    }

    public function test_merges_the_same_policy_entry_found_on_two_pages(): void
    {
        $draft = $this->createDraft();
        $deduplicator = new OnboardingEntityDeduplicator;

        $result = NormalizedExtractionResult::fromArray([
            'schema_version' => '1.0',
            'policies' => [
                $this->policy('Politica de anulare', 'https://example.ro/'),
                $this->policy('Politica de anulare', 'https://example.ro/politici'),
            ],
        ]);

        $processed = $deduplicator->process($draft, $result);

        $this->assertCount(1, $processed->policies);
        $this->assertEqualsCanonicalizing(
            ['https://example.ro/', 'https://example.ro/politici'],
            $processed->policies[0]->sourceUrls
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function staff(string $name, string $role, string $sourceUrl = 'https://example.ro/'): array
    {
        return [
            'name' => ['value' => $name, 'confidence_score' => 0.9, 'requires_confirmation' => false],
            'role' => ['value' => $role, 'confidence_score' => 0.9, 'requires_confirmation' => false],
            'source_urls' => [$sourceUrl],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function faq(string $question, string $sourceUrl = 'https://example.ro/'): array
    {
        return [
            'question' => ['value' => $question, 'confidence_score' => 0.9, 'requires_confirmation' => false],
            'answer' => ['value' => 'Raspuns', 'confidence_score' => 0.9, 'requires_confirmation' => false],
            'source_urls' => [$sourceUrl],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function policy(string $title, string $sourceUrl = 'https://example.ro/'): array
    {
        return [
            'title' => ['value' => $title, 'confidence_score' => 0.9, 'requires_confirmation' => false],
            'content' => ['value' => 'Continut', 'confidence_score' => 0.9, 'requires_confirmation' => false],
            'source_urls' => [$sourceUrl],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function service(string $name, string $category, string $sourceUrl = 'https://example.ro/', ?string $price = null): array
    {
        return [
            'name' => ['value' => $name, 'confidence_score' => 0.9, 'requires_confirmation' => false],
            'category' => ['value' => $category, 'confidence_score' => 0.9, 'requires_confirmation' => false],
            'price' => $price !== null ? ['value' => $price, 'confidence_score' => 0.9, 'requires_confirmation' => false] : null,
            'source_urls' => [$sourceUrl],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function location(string $name, string $address): array
    {
        return [
            'name' => ['value' => $name, 'confidence_score' => 0.9, 'requires_confirmation' => false],
            'address' => ['value' => $address, 'confidence_score' => 0.9, 'requires_confirmation' => false],
            'source_urls' => ['https://example.ro/'],
        ];
    }

    private function createDraft(): OnboardingDraft
    {
        $user = User::factory()->create();
        /** @var Salon $salon */
        $salon = $user->salon()->create(['name' => 'Test Salon']);

        return OnboardingDraft::query()->create([
            'salon_id' => $salon->id,
            'created_by_user_id' => $user->id,
            'source_type' => 'url',
            'source_url' => 'https://example.ro',
            'normalized_source_url' => 'https://example.ro',
            'status' => OnboardingDraftStatus::Pending,
        ]);
    }
}
