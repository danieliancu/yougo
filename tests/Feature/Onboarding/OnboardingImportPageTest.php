<?php

namespace Tests\Feature\Onboarding;

use App\Enums\OnboardingDraftStatus;
use App\Enums\OnboardingState;
use App\Models\OnboardingDraft;
use App\Models\Salon;
use App\Models\User;
use App\Services\Onboarding\OnboardingDraftPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class OnboardingImportPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_is_redirected_to_login(): void
    {
        $this->get('/onboarding/import')->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_the_page(): void
    {
        [, $user] = $this->createSalonAndUser();

        $this->actingAs($user)->get('/onboarding/import')->assertOk();
    }

    public function test_renders_the_onboarding_import_component(): void
    {
        [, $user] = $this->createSalonAndUser();

        $this->actingAs($user)->get('/onboarding/import')
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Onboarding/Import'));
    }

    public function test_only_the_users_own_salon_active_draft_is_exposed(): void
    {
        [$salon, $user] = $this->createSalonAndUser();
        $ownDraft = $this->createActiveDraft($salon, $user);

        $otherUser = User::factory()->create();
        /** @var Salon $otherSalon */
        $otherSalon = $otherUser->salon()->create(['name' => 'Other Salon']);
        $this->createActiveDraft($otherSalon, $otherUser);

        $response = $this->actingAs($user)->get('/onboarding/import');

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Onboarding/Import')
            ->where('active_draft.id', $ownDraft->id)
        );
    }

    public function test_salon_already_past_identity_ready_is_redirected_to_dashboard(): void
    {
        [$salon, $user] = $this->createSalonAndUser();
        $salon->forceFill(['onboarding_state' => OnboardingState::IdentityReady])->save();

        $this->actingAs($user)->get('/onboarding/import')->assertRedirect('/dashboard/overview');
    }

    public function test_presenter_never_exposes_raw_or_internal_fields(): void
    {
        [$salon, $user] = $this->createSalonAndUser();
        $draft = $this->createActiveDraft($salon, $user);

        $draft->forceFill([
            'raw_extraction_result' => ['secret' => 'prompt leak'],
            'raw_result_storage_path' => '/tmp/raw.json',
            'metadata' => [
                'last_analysis' => [
                    'provider_metadata' => ['model' => 'gemini-3-flash-preview', 'pages_discovered' => 3, 'pages_processed' => 2],
                    'warnings' => [],
                ],
            ],
        ])->save();

        $presented = OnboardingDraftPresenter::present($draft->refresh());
        $json = json_encode($presented);

        $this->assertStringNotContainsString('raw_extraction_result', $json);
        $this->assertStringNotContainsString('raw_result_storage_path', $json);
        $this->assertStringNotContainsString('prompt leak', $json);
        $this->assertStringNotContainsString('gemini-3-flash-preview', $json);
        $this->assertArrayNotHasKey('raw_extraction_result', $presented);
        $this->assertArrayNotHasKey('raw_result_storage_path', $presented);
    }

    /**
     * @return array{0: Salon, 1: User}
     */
    private function createSalonAndUser(): array
    {
        $user = User::factory()->create();
        /** @var Salon $salon */
        $salon = $user->salon()->create(['name' => 'Test Salon']);

        return [$salon, $user];
    }

    private function createActiveDraft(Salon $salon, User $user): OnboardingDraft
    {
        return OnboardingDraft::query()->create([
            'salon_id' => $salon->id,
            'created_by_user_id' => $user->id,
            'source_type' => 'url',
            'source_url' => 'https://example.ro',
            'normalized_source_url' => 'https://example.ro',
            'status' => OnboardingDraftStatus::ReviewRequired,
        ]);
    }
}
