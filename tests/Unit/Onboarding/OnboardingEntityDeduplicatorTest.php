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
