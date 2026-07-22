<?php

namespace Tests\Feature\Onboarding;

use App\Models\Salon;
use App\Models\User;
use App\Services\Onboarding\Analyzer\FakeOnboardingSourceAnalyzer;
use App\Services\Onboarding\Analyzer\OnboardingSourceAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingIndustryConflictTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'sync']);
        $this->app->instance(OnboardingSourceAnalyzer::class, new FakeOnboardingSourceAnalyzer);
    }

    public function test_no_conflict_when_detected_matches_selected_and_recommendation_is_still_populated(): void
    {
        [, $user] = $this->createSalonAndUser('salon-beauty');
        $confirm = $this->runFullImportFlow($user);

        $confirm->assertOk();
        $this->assertFalse($confirm->json('industry_review.conflict'));
        $this->assertSame('salon-beauty', $confirm->json('industry_review.detected_business_type'));
        $this->assertSame('appointment', $confirm->json('industry_review.recommended_primary_capability'));
        $this->assertContains('request', $confirm->json('industry_review.recommended_secondary_capabilities'));
    }

    public function test_conflict_flagged_when_detected_differs_from_selected(): void
    {
        [, $user] = $this->createSalonAndUser('auto-service');
        $confirm = $this->runFullImportFlow($user);

        $confirm->assertOk();
        $this->assertTrue($confirm->json('industry_review.conflict'));
        $this->assertSame('salon-beauty', $confirm->json('industry_review.detected_business_type'));
        $this->assertSame('auto-service', $confirm->json('industry_review.selected_business_type'));
    }

    public function test_recommendation_does_not_by_itself_change_the_active_capability(): void
    {
        // Selected 'auto-service' (would recommend request), but the analyzer detects
        // 'salon-beauty' from the site itself — on conflict, the recommendation is based
        // on the more-likely-accurate detected signal (still flagged as a conflict, still
        // requires explicit confirmation either way).
        [, $user] = $this->createSalonAndUser('auto-service');
        $confirmResponse = $this->runFullImportFlow($user);
        $this->assertTrue($confirmResponse->json('industry_review.conflict'));

        $salon = User::find($user->id)->salon;
        // The salon was already active-appointment/confirmed since creation (Salon::booted()
        // defaults) — applyRecommendation() must never override that on its own.
        $this->assertSame('appointment', $salon->recommended_primary_capability);
        $this->assertSame('appointment', $salon->primary_capability);
        $this->assertSame('confirmed', $salon->capabilities_source);
    }

    public function test_confirm_capabilities_endpoint_activates_the_chosen_configuration(): void
    {
        [, $user] = $this->createSalonAndUser('salon-beauty');
        $this->runFullImportFlow($user)->assertOk();
        $salon = User::find($user->id)->salon;

        $response = $this->actingAs(User::find($user->id))->post('/onboarding/capabilities/confirm', [
            'primary_capability' => $salon->recommended_primary_capability,
            'secondary_capabilities' => $salon->recommended_secondary_capabilities,
        ]);

        $response->assertRedirect();
        $salon = User::find($user->id)->salon;
        $this->assertSame($salon->recommended_primary_capability, $salon->primary_capability);
        $this->assertSame('confirmed', $salon->capabilities_source);
    }

    public function test_confirm_capabilities_marks_custom_when_user_changes_the_recommendation(): void
    {
        [, $user] = $this->createSalonAndUser('auto-service');
        $this->runFullImportFlow($user)->assertOk();
        // Recommendation ends up 'appointment'/['request'] (see previous test) — submit
        // something that genuinely differs from it to exercise the 'custom' path.
        $response = $this->actingAs(User::find($user->id))->post('/onboarding/capabilities/confirm', [
            'primary_capability' => 'request',
            'secondary_capabilities' => ['appointment'],
        ]);

        $response->assertRedirect();
        $salon = User::find($user->id)->salon;
        $this->assertSame('request', $salon->primary_capability);
        $this->assertSame('custom', $salon->capabilities_source);
    }

    public function test_registration_without_a_website_populates_recommendation_from_manual_selection(): void
    {
        $response = $this->post('/register', [
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'business_name' => 'Instalatii SRL',
            'business_type' => 'home-services',
        ]);

        $response->assertRedirect();
        $salon = Salon::query()->where('business_type', 'home-services')->firstOrFail();

        $this->assertSame('request', $salon->recommended_primary_capability);
        // The universal safe default (appointment) stays active until the user explicitly
        // confirms the 'request' recommendation — registration itself never activates it.
        $this->assertSame('appointment', $salon->primary_capability);
        $this->assertSame('confirmed', $salon->capabilities_source);
    }

    /**
     * @return array{0: Salon, 1: User}
     */
    private function createSalonAndUser(string $businessType): array
    {
        $user = User::factory()->create();
        /** @var Salon $salon */
        $salon = $user->salon()->create(['name' => '', 'business_type' => $businessType]);

        return [$salon, $user];
    }

    private function runFullImportFlow(User $user)
    {
        $start = $this->actingAs($user)->postJson('/onboarding/import', [
            'source_type' => 'url',
            'source_url' => 'http://93.184.216.34/',
        ]);
        $draftId = $start->json('id');
        $revision = $this->actingAs(User::find($user->id))->getJson("/onboarding/import/{$draftId}")->json('revision');

        return $this->actingAs(User::find($user->id))->postJson("/onboarding/import/{$draftId}/confirm", [
            'expected_revision' => $revision,
        ]);
    }
}
