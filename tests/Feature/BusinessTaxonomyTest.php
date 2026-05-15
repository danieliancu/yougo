<?php

namespace Tests\Feature;

use App\Models\Salon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BusinessTaxonomyTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_updates_business_type_without_requiring_industry(): void
    {
        $user = User::factory()->create();
        $user->salon()->create([
            'name' => 'Studio',
            'business_type' => 'salon-beauty',
            'industry' => 'hair-salon',
            'mode' => Salon::MODE_APPOINTMENT,
        ]);

        $response = $this->actingAs($user)->from('/dashboard/settings')->post('/settings', [
            'name' => 'Owner',
            'business_name' => 'Studio',
            'timezone' => 'Europe/London',
            'business_type' => 'rental',
            'country' => 'GB',
            'website' => '',
            'business_phone' => '',
            'notification_email' => '',
            'email_notifications' => true,
            'missed_call_alerts' => true,
            'booking_confirmations' => true,
            'display_language' => 'en',
            'date_format' => 'DD/MM/YYYY',
        ]);

        $response->assertRedirect();
        $this->assertSame('rental', $user->salon->refresh()->business_type);
    }

    public function test_free_plan_cannot_enable_paid_email_notification_settings(): void
    {
        $user = User::factory()->create();
        $user->salon()->create([
            'name' => 'Studio',
            'plan' => 'free',
            'business_type' => 'salon-beauty',
            'mode' => Salon::MODE_APPOINTMENT,
        ]);

        $this->actingAs($user)->from('/dashboard/settings')->post('/settings', $this->validSettingsPayload([
            'email_notifications' => true,
            'booking_confirmations' => true,
        ]))->assertRedirect();

        $salon = $user->salon->refresh();
        $this->assertFalse($salon->email_notifications);
        $this->assertFalse($salon->booking_confirmations);
    }

    public function test_missed_call_alerts_require_phone_enabled_plan(): void
    {
        $user = User::factory()->create();
        $user->salon()->create([
            'name' => 'Studio',
            'plan' => 'website_chat',
            'business_type' => 'salon-beauty',
            'mode' => Salon::MODE_APPOINTMENT,
        ]);

        $this->actingAs($user)->from('/dashboard/settings')->post('/settings', $this->validSettingsPayload([
            'missed_call_alerts' => true,
        ]))->assertRedirect();

        $this->assertFalse($user->salon->refresh()->missed_call_alerts);
    }

    public function test_missed_call_alerts_can_be_enabled_on_phone_plan(): void
    {
        $user = User::factory()->create();
        $user->salon()->create([
            'name' => 'Studio',
            'plan' => 'voice_starter',
            'business_type' => 'salon-beauty',
            'mode' => Salon::MODE_APPOINTMENT,
        ]);

        $this->actingAs($user)->from('/dashboard/settings')->post('/settings', $this->validSettingsPayload([
            'missed_call_alerts' => true,
        ]))->assertRedirect();

        $this->assertTrue($user->salon->refresh()->missed_call_alerts);
    }

    public function test_public_business_type_page_loads(): void
    {
        $this->get('/industries/clinic-healthcare')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Industries/Show')
                ->where('businessType.slug', 'clinic-healthcare')
            );
    }

    public function test_invalid_business_type_page_returns_404(): void
    {
        $this->get('/industries/not-real')->assertNotFound();
    }

    public function test_old_industry_route_redirects_to_parent_business_type_page(): void
    {
        $this->get('/industries/auto-service/mot-inspection')
            ->assertRedirect('/industries/auto-service');
    }

    public function test_public_navigation_can_access_business_taxonomy_data(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Landing')
                ->has('businessTaxonomy.0')
            );
    }

    public function test_dashboard_still_loads_for_authenticated_user(): void
    {
        $user = User::factory()->create();
        $user->salon()->create([
            'name' => 'Studio',
            'business_type' => 'salon-beauty',
            'industry' => 'hair-salon',
            'mode' => Salon::MODE_APPOINTMENT,
        ]);

        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    private function validSettingsPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Owner',
            'business_name' => 'Studio',
            'timezone' => 'Europe/London',
            'business_type' => 'salon-beauty',
            'country' => 'GB',
            'website' => '',
            'business_phone' => '',
            'notification_email' => 'owner@example.com',
            'email_notifications' => true,
            'missed_call_alerts' => false,
            'booking_confirmations' => true,
            'display_language' => 'en',
            'date_format' => 'DD/MM/YYYY',
        ], $overrides);
    }
}
