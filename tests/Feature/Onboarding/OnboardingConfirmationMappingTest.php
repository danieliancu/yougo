<?php

namespace Tests\Feature\Onboarding;

use App\DataTransferObjects\Onboarding\ConfirmedSelections;
use App\Enums\OnboardingDraftStatus;
use App\Enums\OnboardingState;
use App\Models\OnboardingDraft;
use App\Models\Salon;
use App\Models\User;
use App\Services\Onboarding\OnboardingDraftConfirmationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingConfirmationMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirming_maps_business_type_description_and_language(): void
    {
        [$salon, $user, $draft] = $this->createDraft();

        $salon = $this->service()->confirm($draft, $user, ConfirmedSelections::fromArray(['expected_revision' => $draft->revision]));

        $this->assertSame('salon-beauty', $salon->business_type);
        $this->assertSame('Cel mai bun salon din oraș.', $salon->ai_about_business);
        $this->assertSame('ro', $salon->ai_language_mode);
    }

    public function test_ambiguous_language_is_not_written(): void
    {
        [$salon, $user, $draft] = $this->createDraft(languages: ['romana', 'engleza']);

        $salon = $this->service()->confirm($draft, $user, ConfirmedSelections::fromArray(['expected_revision' => $draft->revision]));

        // Ambiguous (multiple distinct languages) -> left untouched at its default,
        // never written to ro/en.
        $this->assertSame('auto', $salon->ai_language_mode);
    }

    public function test_does_not_overwrite_existing_business_type_without_explicit_overwrite(): void
    {
        [$salon, $user, $draft] = $this->createDraft();
        $salon->forceFill(['business_type' => 'clinica-veterinara'])->save();

        $salon = $this->service()->confirm($draft->refresh(), $user, ConfirmedSelections::fromArray(['expected_revision' => $draft->revision]));

        $this->assertSame('clinica-veterinara', $salon->business_type);
    }

    public function test_confirming_syncs_the_service_category_into_salon_service_categories(): void
    {
        // Service.type is set directly from the AI-extracted category, but the "manage
        // categories" screen (ServiceController::updateCategories) reads/writes the
        // separate Salon.service_categories list — without syncing the two, that screen
        // would show an empty list for a salon whose services already have categories,
        // and saving it would wipe every service's type back to null.
        [$salon, $user, $draft] = $this->createDraft();

        $this->service()->confirm($draft, $user, ConfirmedSelections::fromArray(['expected_revision' => $draft->revision]));

        $this->assertContains('unghii', $salon->fresh()->service_categories);
    }

    public function test_staff_faq_and_policies_are_written_to_live_tables(): void
    {
        [$salon, $user, $draft] = $this->createDraft();

        $this->service()->confirm($draft, $user, ConfirmedSelections::fromArray(['expected_revision' => $draft->revision]));

        $this->assertSame(1, $salon->staff()->count());
        $this->assertSame('Ana Pop', $salon->staff()->first()->name);
        $this->assertSame(1, $salon->faqs()->count());
        $this->assertSame('Program?', $salon->faqs()->first()->question);
        $this->assertSame(1, $salon->policies()->count());
        $this->assertSame('Anulare', $salon->policies()->first()->title);
    }

    public function test_reconfirming_the_same_draft_does_not_duplicate_staff_faq_or_policies(): void
    {
        [$salon, $user, $draft] = $this->createDraft();

        $selections = ConfirmedSelections::fromArray(['expected_revision' => $draft->revision]);
        $this->service()->confirm($draft, $user, $selections);
        // Idempotent replay: same draft, already confirmed, must be a no-op — not a second write.
        $this->service()->confirm($draft->refresh(), $user, $selections);

        $this->assertSame(1, $salon->staff()->count());
        $this->assertSame(1, $salon->faqs()->count());
        $this->assertSame(1, $salon->policies()->count());
    }

    public function test_excluding_a_staff_member_skips_it_and_does_not_block_confirmation(): void
    {
        [$salon, $user, $draft] = $this->createDraft(staffFingerprint: 'staff-fp-1', requiresConfirmation: true);

        $this->service()->confirm($draft, $user, ConfirmedSelections::fromArray([
            'expected_revision' => $draft->revision,
            'excluded_staff' => ['staff-fp-1'],
        ]));

        $this->assertSame(0, $salon->staff()->count());
    }

    public function test_a_non_numeric_duration_falls_back_to_the_default_instead_of_crashing(): void
    {
        // Reproduces a real extraction: the AI put a maintenance interval ("come back in
        // 3-5 months") in the duration field instead of an appointment length. That must
        // never reach the `services.duration` unsigned-integer column as-is — MySQL
        // truncation-errors on it under strict mode, taking the whole confirm down.
        [$salon, $user, $draft] = $this->createDraft(serviceDuration: '3-5 luni (întreținere)');

        $this->service()->confirm($draft, $user, ConfirmedSelections::fromArray(['expected_revision' => $draft->revision]));

        $this->assertSame(30, $salon->services()->first()->duration);
    }

    public function test_a_duration_with_an_explicit_unit_is_read_correctly(): void
    {
        [$salon, $user, $draft] = $this->createDraft(serviceDuration: '45 minute');

        $this->service()->confirm($draft, $user, ConfirmedSelections::fromArray(['expected_revision' => $draft->revision]));

        $this->assertSame(45, $salon->services()->first()->duration);
    }

    private function service(): OnboardingDraftConfirmationService
    {
        return $this->app->make(OnboardingDraftConfirmationService::class);
    }

    /**
     * @return array{0: Salon, 1: User, 2: OnboardingDraft}
     */
    private function createDraft(mixed $languages = 'romana', ?string $staffFingerprint = null, bool $requiresConfirmation = false, ?string $serviceDuration = null): array
    {
        $user = User::factory()->create();
        /** @var Salon $salon */
        $salon = $user->salon()->create(['name' => '']);

        $normalized = [
            'schema_version' => '1.0',
            'business' => [
                'name' => $this->fact('Fake Studio'),
                'business_type' => $this->fact('salon-beauty'),
                'description' => $this->fact('Cel mai bun salon din oraș.'),
                'languages' => $this->fact($languages),
                'service_at_customer_location' => $this->fact(false),
            ],
            'locations' => [
                [
                    'name' => $this->fact('Central'),
                    'address' => $this->fact('Str. Exemplu 1'),
                    'opening_hours' => $this->fact(['mon' => '09:00 - 18:00']),
                    'fingerprint' => hash('sha256', 'central'),
                    'is_temporary_fingerprint' => false,
                ],
            ],
            'services' => [
                array_filter([
                    'name' => $this->fact('Manichiura'),
                    'category' => $this->fact('unghii'),
                    'price' => $this->fact('100'),
                    'duration' => $serviceDuration !== null ? $this->fact($serviceDuration) : null,
                    'fingerprint' => hash('sha256', 'manichiura'),
                    'is_temporary_fingerprint' => false,
                ], static fn ($value) => $value !== null),
            ],
            'staff' => [
                [
                    'name' => $this->fact('Ana Pop', $requiresConfirmation),
                    'role' => $this->fact('Stilist'),
                    'fingerprint' => $staffFingerprint,
                    'is_temporary_fingerprint' => $staffFingerprint !== null,
                ],
            ],
            'faq' => [
                ['question' => $this->fact('Program?'), 'answer' => $this->fact('Luni-Vineri')],
            ],
            'policies' => [
                ['title' => $this->fact('Anulare'), 'content' => $this->fact('Cu 24h inainte.')],
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
    private function fact(mixed $value, bool $requiresConfirmation = false): array
    {
        return ['value' => $value, 'confidence_score' => 0.9, 'requires_confirmation' => $requiresConfirmation];
    }
}
