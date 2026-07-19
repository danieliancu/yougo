<?php

namespace Tests\Feature\Onboarding;

use App\DataTransferObjects\Onboarding\ConfirmedSelections;
use App\Enums\OnboardingDraftStatus;
use App\Enums\OnboardingState;
use App\Exceptions\Onboarding\IncompleteOnboardingDraftException;
use App\Exceptions\Onboarding\MissingFactDecisionsException;
use App\Exceptions\Onboarding\OnboardingRevisionConflictException;
use App\Models\OnboardingDraft;
use App\Models\Salon;
use App\Models\User;
use App\Services\Onboarding\OnboardingDraftConfirmationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingDraftConfirmationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirms_a_fully_certain_draft_and_reaches_identity_ready(): void
    {
        [$salon, $user, $draft] = $this->createReviewableDraft();

        $salon = $this->service()->confirm($draft, $user, ConfirmedSelections::fromArray(['expected_revision' => $draft->revision]));

        $this->assertSame(OnboardingState::IdentityReady, $salon->onboarding_state);
        $this->assertSame(OnboardingDraftStatus::Confirmed, $draft->refresh()->status);
        $this->assertSame('Fake Studio', $salon->name);
        $this->assertSame(1, $salon->locations()->count());
        $this->assertSame(1, $salon->services()->count());
        $this->assertNotNull($draft->confirmed_at);
        $this->assertSame($user->id, $draft->confirmed_by_user_id);
        $this->assertNotEmpty($draft->metadata['confirmation']['facts']);
    }

    public function test_double_confirm_is_an_idempotent_no_op(): void
    {
        [$salon, $user, $draft] = $this->createReviewableDraft();
        $selections = ConfirmedSelections::fromArray(['expected_revision' => $draft->revision]);

        $this->service()->confirm($draft, $user, $selections);
        $locationCountAfterFirst = $salon->locations()->count();
        $serviceCountAfterFirst = $salon->services()->count();

        // A second confirm call, even with a now-stale expected_revision, must be a
        // safe no-op — a confirmed draft has nothing left to conflict over.
        $result = $this->service()->confirm($draft->refresh(), $user, $selections);

        $this->assertSame(OnboardingState::IdentityReady, $result->onboarding_state);
        $this->assertSame($locationCountAfterFirst, $salon->locations()->count());
        $this->assertSame($serviceCountAfterFirst, $salon->services()->count());
    }

    public function test_revision_mismatch_is_rejected(): void
    {
        [, $user, $draft] = $this->createReviewableDraft();

        $this->expectException(OnboardingRevisionConflictException::class);
        $this->service()->confirm($draft, $user, ConfirmedSelections::fromArray(['expected_revision' => $draft->revision + 1]));
    }

    public function test_uncertain_fact_without_decision_is_rejected_and_nothing_persists(): void
    {
        [$salon, $user, $draft] = $this->createDraftWithUncertainPrice();

        try {
            $this->service()->confirm($draft, $user, ConfirmedSelections::fromArray(['expected_revision' => $draft->revision]));
            $this->fail('Expected MissingFactDecisionsException.');
        } catch (MissingFactDecisionsException $exception) {
            $this->assertContains('services.'.$draft->normalized_extraction_result['services'][0]['fingerprint'].'.price', $exception->missingPaths);
        }

        $draft->refresh();
        $salon->refresh();
        $this->assertSame(OnboardingDraftStatus::ReviewRequired, $draft->status);
        $this->assertSame(OnboardingState::ReviewRequired, $salon->onboarding_state);
        $this->assertSame(0, $salon->services()->count());
        $this->assertArrayNotHasKey('confirmation', $draft->metadata ?? []);
    }

    public function test_uncertain_fact_confirmed_applies_analyzer_value(): void
    {
        [$salon, $user, $draft] = $this->createDraftWithUncertainPrice();
        $fingerprint = $draft->normalized_extraction_result['services'][0]['fingerprint'];

        $salon = $this->service()->confirm($draft, $user, ConfirmedSelections::fromArray([
            'expected_revision' => $draft->revision,
            'facts' => [
                "services.{$fingerprint}.price" => ['decision' => 'confirmed'],
            ],
        ]));

        $this->assertSame('99', $salon->services()->first()->price);
    }

    public function test_uncertain_fact_corrected_uses_the_supplied_value(): void
    {
        [$salon, $user, $draft] = $this->createDraftWithUncertainPrice();
        $fingerprint = $draft->normalized_extraction_result['services'][0]['fingerprint'];

        $salon = $this->service()->confirm($draft, $user, ConfirmedSelections::fromArray([
            'expected_revision' => $draft->revision,
            'facts' => [
                "services.{$fingerprint}.price" => ['decision' => 'corrected', 'value' => '150'],
            ],
        ]));

        $this->assertSame('150', $salon->services()->first()->price);
    }

    public function test_missing_business_name_is_rejected(): void
    {
        [$salon, $user, $draft] = $this->createReviewableDraft(withBusinessName: false);

        $this->expectException(IncompleteOnboardingDraftException::class);

        try {
            $this->service()->confirm($draft, $user, ConfirmedSelections::fromArray(['expected_revision' => $draft->revision]));
        } catch (IncompleteOnboardingDraftException $exception) {
            $this->assertContains('business_name_missing', $exception->failedConditions);

            throw $exception;
        }
    }

    public function test_no_location_and_no_service_area_is_rejected(): void
    {
        [$salon, $user, $draft] = $this->createReviewableDraft(withLocation: false);

        try {
            $this->service()->confirm($draft, $user, ConfirmedSelections::fromArray(['expected_revision' => $draft->revision]));
            $this->fail('Expected IncompleteOnboardingDraftException.');
        } catch (IncompleteOnboardingDraftException $exception) {
            $this->assertContains('no_location_and_no_customer_service_area', $exception->failedConditions);
        }
    }

    public function test_business_opening_hours_satisfies_completeness_when_service_at_customer_location(): void
    {
        [$salon, $user, $draft] = $this->createReviewableDraft(withLocation: false, serviceAtCustomerLocation: true, withBusinessHours: true);

        $salon = $this->service()->confirm($draft, $user, ConfirmedSelections::fromArray(['expected_revision' => $draft->revision]));

        $this->assertSame(OnboardingState::IdentityReady, $salon->onboarding_state);
        $this->assertTrue($salon->service_at_customer_location);
        $this->assertNotEmpty($salon->opening_hours);
    }

    public function test_no_services_is_rejected(): void
    {
        [$salon, $user, $draft] = $this->createReviewableDraft(withService: false);

        try {
            $this->service()->confirm($draft, $user, ConfirmedSelections::fromArray(['expected_revision' => $draft->revision]));
            $this->fail('Expected IncompleteOnboardingDraftException.');
        } catch (IncompleteOnboardingDraftException $exception) {
            $this->assertContains('no_services', $exception->failedConditions);
        }
    }

    public function test_does_not_overwrite_existing_manual_business_name(): void
    {
        [$salon, $user, $draft] = $this->createReviewableDraft();
        $salon->forceFill(['name' => 'Manually Entered Name'])->save();

        $salon = $this->service()->confirm($draft->refresh(), $user, ConfirmedSelections::fromArray(['expected_revision' => $draft->revision]));

        $this->assertSame('Manually Entered Name', $salon->name);
    }

    public function test_explicit_overwrite_replaces_existing_manual_value(): void
    {
        [$salon, $user, $draft] = $this->createReviewableDraft();
        $salon->forceFill(['name' => 'Manually Entered Name'])->save();

        $salon = $this->service()->confirm($draft->refresh(), $user, ConfirmedSelections::fromArray([
            'expected_revision' => $draft->revision,
            'overwrite' => ['name' => true],
        ]));

        $this->assertSame('Fake Studio', $salon->name);
    }

    private function service(): OnboardingDraftConfirmationService
    {
        return $this->app->make(OnboardingDraftConfirmationService::class);
    }

    /**
     * @return array{0: Salon, 1: User, 2: OnboardingDraft}
     */
    private function createReviewableDraft(
        bool $withBusinessName = true,
        bool $withLocation = true,
        bool $withService = true,
        bool $serviceAtCustomerLocation = false,
        bool $withBusinessHours = false,
    ): array {
        $user = User::factory()->create();
        /** @var Salon $salon */
        $salon = $user->salon()->create(['name' => '', 'business_type' => 'salon-beauty']);

        $normalized = [
            'schema_version' => '1.0',
            'business' => [
                'name' => $withBusinessName ? $this->fact('Fake Studio') : null,
                'service_at_customer_location' => $this->fact($serviceAtCustomerLocation),
                'opening_hours' => $withBusinessHours ? $this->fact(['mon' => '09:00 - 18:00']) : null,
            ],
            'locations' => $withLocation ? [
                [
                    'name' => $this->fact('Central'),
                    'address' => $this->fact('Str. Exemplu 1'),
                    'phone' => $this->fact('+40700000000'),
                    'opening_hours' => $this->fact(['mon' => '09:00 - 18:00', 'tue' => '09:00 - 18:00']),
                    'source_urls' => ['https://example.com/'],
                    'fingerprint' => hash('sha256', 'central-fingerprint'),
                    'is_temporary_fingerprint' => false,
                ],
            ] : [],
            'services' => $withService ? [
                [
                    'name' => $this->fact('Manichiura'),
                    'category' => $this->fact('unghii'),
                    'price' => $this->fact('100'),
                    'duration' => $this->fact(30),
                    'source_urls' => ['https://example.com/'],
                    'fingerprint' => hash('sha256', 'manichiura-fingerprint'),
                    'is_temporary_fingerprint' => false,
                ],
            ] : [],
        ];

        $draft = OnboardingDraft::query()->create([
            'salon_id' => $salon->id,
            'created_by_user_id' => $user->id,
            'source_type' => 'url',
            'source_url' => 'https://example.com',
            'normalized_source_url' => 'https://example.com',
            'status' => OnboardingDraftStatus::ReviewRequired,
            'normalized_extraction_result' => $normalized,
            'revision' => 1,
        ]);

        $salon->forceFill(['onboarding_state' => OnboardingState::ReviewRequired])->save();

        return [$salon, $user, $draft];
    }

    /**
     * @return array{0: Salon, 1: User, 2: OnboardingDraft}
     */
    private function createDraftWithUncertainPrice(): array
    {
        $user = User::factory()->create();
        /** @var Salon $salon */
        $salon = $user->salon()->create(['name' => '', 'business_type' => 'salon-beauty']);

        $fingerprint = hash('sha256', 'manichiura-fingerprint');

        $normalized = [
            'schema_version' => '1.0',
            'business' => [
                'name' => $this->fact('Fake Studio'),
            ],
            'locations' => [
                [
                    'name' => $this->fact('Central'),
                    'address' => $this->fact('Str. Exemplu 1'),
                    'opening_hours' => $this->fact(['mon' => '09:00 - 18:00']),
                    'fingerprint' => hash('sha256', 'central-fingerprint'),
                    'is_temporary_fingerprint' => false,
                ],
            ],
            'services' => [
                [
                    'name' => $this->fact('Manichiura'),
                    'category' => $this->fact('unghii'),
                    'price' => $this->fact('99', requiresConfirmation: true, reason: 'conflicting prices found'),
                    'duration' => $this->fact(30),
                    'fingerprint' => $fingerprint,
                    'is_temporary_fingerprint' => false,
                ],
            ],
        ];

        $draft = OnboardingDraft::query()->create([
            'salon_id' => $salon->id,
            'created_by_user_id' => $user->id,
            'source_type' => 'url',
            'source_url' => 'https://example.com',
            'normalized_source_url' => 'https://example.com',
            'status' => OnboardingDraftStatus::ReviewRequired,
            'normalized_extraction_result' => $normalized,
            'revision' => 1,
        ]);

        $salon->forceFill(['onboarding_state' => OnboardingState::ReviewRequired])->save();

        return [$salon, $user, $draft];
    }

    /**
     * @return array<string, mixed>
     */
    private function fact(mixed $value, bool $requiresConfirmation = false, ?string $reason = null): array
    {
        return [
            'value' => $value,
            'confidence_score' => 0.9,
            'requires_confirmation' => $requiresConfirmation,
            'reason' => $reason,
        ];
    }
}
