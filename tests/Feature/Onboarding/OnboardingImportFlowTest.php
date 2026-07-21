<?php

namespace Tests\Feature\Onboarding;

use App\Enums\OnboardingState;
use App\Models\OnboardingDraft;
use App\Models\Salon;
use App\Models\User;
use App\Services\Onboarding\Analyzer\FakeOnboardingSourceAnalyzer;
use App\Services\Onboarding\Analyzer\OnboardingSourceAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingImportFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Run the queued analysis job inline so these HTTP-level tests can exercise
        // the full start -> analyse -> review_required -> confirm flow synchronously.
        config(['queue.default' => 'sync']);
        $this->app->instance(OnboardingSourceAnalyzer::class, new FakeOnboardingSourceAnalyzer);
    }

    public function test_full_flow_from_start_to_identity_ready(): void
    {
        [$salon, $user] = $this->createSalonAndUser();

        $startResponse = $this->actingAs($user)->postJson('/onboarding/import', [
            'source_type' => 'url',
            'source_url' => 'http://93.184.216.34/',
        ]);

        $startResponse->assertOk();
        $draftId = $startResponse->json('id');
        $this->assertSame('review_required', $startResponse->json('status'));
        $this->assertArrayNotHasKey('raw_extraction_result', $startResponse->json());

        $statusResponse = $this->actingAs($user)->getJson("/onboarding/import/{$draftId}");
        $statusResponse->assertOk()->assertJson(['status' => 'review_required']);

        $revision = $statusResponse->json('revision');

        $confirmResponse = $this->actingAs($user)->postJson("/onboarding/import/{$draftId}/confirm", [
            'expected_revision' => $revision,
        ]);

        $confirmResponse->assertOk();
        $this->assertSame('confirmed', $confirmResponse->json('draft.status'));
        $this->assertSame('identity_ready', $confirmResponse->json('salon.onboarding_state'));

        $salon->refresh();
        $this->assertSame('Fake Studio', $salon->name);
        $this->assertSame(OnboardingState::IdentityReady, $salon->onboarding_state);
    }

    public function test_active_endpoint_returns_current_draft_for_resume_after_logout(): void
    {
        [$salon, $user] = $this->createSalonAndUser();

        $this->actingAs($user)->postJson('/onboarding/import', [
            'source_type' => 'url',
            'source_url' => 'http://93.184.216.34/',
        ])->assertOk();

        // Simulate a fresh session after logout/login.
        $activeResponse = $this->actingAs($user)->getJson('/onboarding/import/active');

        $activeResponse->assertOk();
        $this->assertNotNull($activeResponse->json('draft'));
        $this->assertSame('review_required', $activeResponse->json('draft.status'));
    }

    public function test_tenant_isolation_across_salons(): void
    {
        [, $ownerUser] = $this->createSalonAndUser();
        [, $otherUser] = $this->createSalonAndUser();

        $draft = $this->actingAs($ownerUser)->postJson('/onboarding/import', [
            'source_type' => 'url',
            'source_url' => 'http://93.184.216.34/',
        ])->json();

        $this->actingAs($otherUser)->getJson("/onboarding/import/{$draft['id']}")->assertForbidden();
        $this->actingAs($otherUser)->postJson("/onboarding/import/{$draft['id']}/retry")->assertForbidden();
        $this->actingAs($otherUser)->postJson("/onboarding/import/{$draft['id']}/confirm", ['expected_revision' => 0])->assertForbidden();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->postJson('/onboarding/import', ['source_type' => 'url', 'source_url' => 'http://93.184.216.34/'])
            ->assertUnauthorized();
    }

    public function test_dangerous_url_is_rejected_with_422(): void
    {
        [, $user] = $this->createSalonAndUser();

        $response = $this->actingAs($user)->postJson('/onboarding/import', [
            'source_type' => 'url',
            'source_url' => 'http://127.0.0.1/',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, OnboardingDraft::query()->count());
    }

    public function test_revision_conflict_on_confirm_returns_409(): void
    {
        [, $user] = $this->createSalonAndUser();

        $startResponse = $this->actingAs($user)->postJson('/onboarding/import', [
            'source_type' => 'url',
            'source_url' => 'http://93.184.216.34/',
        ]);

        $draftId = $startResponse->json('id');

        $this->actingAs($user)->postJson("/onboarding/import/{$draftId}/confirm", ['expected_revision' => 999])
            ->assertStatus(409);
    }

    public function test_existing_onboarding_checklist_still_works(): void
    {
        [$salon, $user] = $this->createSalonAndUser();

        $this->actingAs($user)->get('/dashboard/onboarding')->assertOk();
        $this->actingAs($user)->post('/onboarding/skip')->assertRedirect();
        $this->assertTrue($salon->refresh()->onboarding_skipped);
    }

    /**
     * @return array{0: Salon, 1: User}
     */
    private function createSalonAndUser(): array
    {
        $user = User::factory()->create();
        /** @var Salon $salon */
        $salon = $user->salon()->create(['name' => '', 'business_type' => 'salon-beauty']);

        return [$salon, $user];
    }
}
