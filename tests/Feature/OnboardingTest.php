<?php

namespace Tests\Feature;

use App\Models\Salon;
use App\Models\User;
use App\Services\Onboarding\OnboardingChecklistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_registered_user_is_redirected_to_onboarding(): void
    {
        $response = $this->post('/register', [
            'name' => 'Alex Owner',
            'email' => 'alex@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'business_name' => 'Alex Studio',
            'business_type' => 'salon-beauty',
        ]);

        $response->assertRedirect('/onboarding/import');
    }

    public function test_onboarding_checklist_returns_incomplete_steps_for_new_user(): void
    {
        $salon = $this->createSalon();

        $checklist = app(OnboardingChecklistService::class)->forSalon($salon);

        $this->assertSame(0, $checklist['progress']);
        $this->assertFalse($checklist['can_complete']);
        $this->assertFalse($this->step($checklist, 'business_profile')['completed']);
        $this->assertFalse($this->step($checklist, 'location')['completed']);
        $this->assertFalse($this->step($checklist, 'notification_email')['completed']);
        $this->assertFalse($this->step($checklist, 'opening_hours')['completed']);
        $this->assertFalse($this->step($checklist, 'service')['completed']);
        $this->assertFalse($this->step($checklist, 'ai_assistant')['completed']);
    }

    public function test_location_service_and_ai_steps_become_complete_from_real_data(): void
    {
        $salon = $this->createSalon();
        $location = $salon->locations()->create([
            'name' => 'Central',
            'address' => 'Main Street',
            'hours' => ['mon' => '09:00 - 18:00'],
        ]);
        $salon->services()->create([
            'name' => 'Consultatie',
            'price' => '100',
            'duration' => 30,
            'location_ids' => [$location->id],
        ]);
        $salon->update([
            'ai_business_summary' => 'Salon premium cu servicii rapide.',
            'ai_assistant_setup_completed' => true,
            'widget_setup_completed' => true,
            'website' => 'https://example.com',
            'business_phone' => '+40700000000',
            'notification_email' => 'owner@example.com',
        ]);

        $checklist = app(OnboardingChecklistService::class)->forSalon($salon);

        $this->assertTrue($this->step($checklist, 'location')['completed']);
        $this->assertTrue($this->step($checklist, 'service')['completed']);
        $this->assertTrue($this->step($checklist, 'ai_assistant')['completed']);
        $this->assertTrue($this->step($checklist, 'opening_hours')['completed']);
        $this->assertTrue($checklist['can_complete']);
    }

    public function test_ai_assistant_step_is_driven_by_the_manual_completion_flag(): void
    {
        // Deriving completion from whether any AI content field was filled meant the
        // step could flip to "complete" from data auto-filled during import (e.g.
        // business.description -> ai_about_business), without the owner ever actually
        // reviewing the AI settings page. Completion is now an explicit, owner-driven
        // flag set only via the "mark as complete" action on that page.
        $salon = $this->createSalon();
        $salon->update([
            'ai_business_summary' => 'Salon premium cu servicii rapide.',
            'ai_custom_context' => ['Clientii pot cere programari rapide.'],
        ]);

        $checklist = app(OnboardingChecklistService::class)->forSalon($salon->refresh());

        $this->assertFalse($this->step($checklist, 'ai_assistant')['completed']);

        $salon->update(['ai_assistant_setup_completed' => true]);

        $checklist = app(OnboardingChecklistService::class)->forSalon($salon->refresh());

        $this->assertTrue($this->step($checklist, 'ai_assistant')['completed']);
    }

    public function test_staff_and_capacity_rules_do_not_block_completion(): void
    {
        $salon = $this->createCompleteRequiredSalon();

        $checklist = app(OnboardingChecklistService::class)->forSalon($salon);

        $this->assertFalse($this->step($checklist, 'staff')['required']);
        $this->assertTrue($this->step($checklist, 'staff')['optional']);
        $this->assertFalse($this->step($checklist, 'capacity_rules')['required']);
        $this->assertTrue($this->step($checklist, 'capacity_rules')['optional']);
        $this->assertTrue($checklist['can_complete']);
    }

    public function test_install_widget_is_required_and_driven_by_the_manual_completion_flag(): void
    {
        // Completion used to follow widget_enabled, a feature toggle that defaults to
        // true for every salon — meaning this step was effectively always "complete" out
        // of the box, regardless of whether the owner ever opened the widget page.
        // Completion is now an explicit, owner-driven flag, independent of the toggle —
        // and, per explicit product decision, this step is required: it blocks
        // can_complete until the owner marks it done themselves.
        $salon = $this->createCompleteRequiredSalon();
        $salon->update(['widget_setup_completed' => false]);

        $checklist = app(OnboardingChecklistService::class)->forSalon($salon->refresh());
        $this->assertTrue($this->step($checklist, 'install_widget')['required']);
        $this->assertFalse($this->step($checklist, 'install_widget')['optional']);
        $this->assertFalse($this->step($checklist, 'install_widget')['completed']);
        $this->assertFalse($checklist['can_complete']);

        $salon->update(['widget_enabled' => true]);
        $checklist = app(OnboardingChecklistService::class)->forSalon($salon->refresh());
        $this->assertFalse($this->step($checklist, 'install_widget')['completed']);

        $salon->update(['widget_setup_completed' => true]);
        $checklist = app(OnboardingChecklistService::class)->forSalon($salon->refresh());
        $this->assertTrue($this->step($checklist, 'install_widget')['completed']);
        $this->assertTrue($checklist['can_complete']);
    }

    public function test_user_can_skip_onboarding_without_blocking_dashboard(): void
    {
        [$salon, $user] = $this->createSalonWithUser();

        $this->actingAs($user)->post('/onboarding/skip')->assertRedirect();

        $salon->refresh();
        $this->assertTrue($salon->onboarding_skipped);
        $this->assertNotNull($salon->onboarding_skipped_at);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('onboarding.skipped', true)
            );
    }

    public function test_user_cannot_complete_onboarding_when_required_steps_are_missing(): void
    {
        [, $user] = $this->createSalonWithUser();

        $response = $this->actingAs($user)->from('/dashboard/onboarding')->post('/onboarding/complete');

        $response->assertRedirect('/dashboard/onboarding');
        $response->assertSessionHasErrors('onboarding');
    }

    public function test_user_can_complete_onboarding_when_required_steps_are_done(): void
    {
        $salon = $this->createCompleteRequiredSalon();
        $user = $salon->user;

        $this->actingAs($user)->post('/onboarding/complete')->assertRedirect();

        $salon->refresh();
        $this->assertTrue($salon->onboarding_completed);
        $this->assertNotNull($salon->onboarding_completed_at);
    }

    public function test_dashboard_overview_includes_onboarding_reminder_data_when_not_completed(): void
    {
        [, $user] = $this->createSalonWithUser();

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('onboarding.completed', false)
                ->where('onboarding.next_step.key', 'business_profile')
                ->has('onboarding.steps', 10)
            );
    }

    private function createCompleteRequiredSalon(): Salon
    {
        $salon = $this->createSalon();
        $salon->update([
            'website' => 'https://example.com',
            'business_phone' => '+40700000000',
            'notification_email' => 'owner@example.com',
        ]);
        $location = $salon->locations()->create([
            'name' => 'Central',
            'address' => 'Main Street',
            'hours' => ['mon' => '09:00 - 18:00'],
        ]);
        $salon->services()->create([
            'name' => 'Consultatie',
            'price' => '100',
            'duration' => 30,
            'location_ids' => [$location->id],
        ]);
        $salon->update([
            'ai_assistant_setup_completed' => true,
            'widget_setup_completed' => true,
        ]);

        return $salon->refresh();
    }

    private function createSalon(): Salon
    {
        return $this->createSalonWithUser()[0];
    }

    private function createSalonWithUser(): array
    {
        $user = User::factory()->create();
        $salon = $user->salon()->create([
            'name' => 'YouGo Studio',
            'business_type' => 'salon-beauty',
        ]);

        return [$salon, $user];
    }

    private function step(array $checklist, string $key): array
    {
        return collect($checklist['steps'])->firstWhere('key', $key);
    }
}
