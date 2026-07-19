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

    public function test_staff_faq_and_policies_are_not_written_to_live_tables(): void
    {
        [$salon, $user, $draft] = $this->createDraft();

        $this->service()->confirm($draft, $user, ConfirmedSelections::fromArray(['expected_revision' => $draft->revision]));

        $this->assertSame(0, $salon->staff()->count());
        $this->assertNotEmpty($draft->refresh()->normalized_extraction_result['staff'] ?? []);
        $this->assertNotEmpty($draft->normalized_extraction_result['faq'] ?? []);
        $this->assertNotEmpty($draft->normalized_extraction_result['policies'] ?? []);
    }

    private function service(): OnboardingDraftConfirmationService
    {
        return $this->app->make(OnboardingDraftConfirmationService::class);
    }

    /**
     * @return array{0: Salon, 1: User, 2: OnboardingDraft}
     */
    private function createDraft(mixed $languages = 'romana'): array
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
                [
                    'name' => $this->fact('Manichiura'),
                    'category' => $this->fact('unghii'),
                    'price' => $this->fact('100'),
                    'fingerprint' => hash('sha256', 'manichiura'),
                    'is_temporary_fingerprint' => false,
                ],
            ],
            'staff' => [
                ['name' => $this->fact('Ana Pop'), 'role' => $this->fact('Stilist')],
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
    private function fact(mixed $value): array
    {
        return ['value' => $value, 'confidence_score' => 0.9, 'requires_confirmation' => false];
    }
}
