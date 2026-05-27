<?php

namespace Tests\Feature;

use App\Models\Salon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    public function test_authenticated_user_can_update_display_language_only(): void
    {
        $user = User::factory()->create();
        $salon = $user->salon()->create([
            'name' => 'Studio',
            'business_type' => 'salon-beauty',
            'display_language' => 'ro',
        ]);

        $this->actingAs($user)
            ->postJson('/settings/language', ['display_language' => 'en'])
            ->assertOk()
            ->assertJsonPath('locale', 'en');

        $this->assertSame('en', $salon->refresh()->display_language);
    }

    public function test_free_plan_can_enable_booking_email_notification_settings(): void
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
        $this->assertTrue($salon->email_notifications);
        $this->assertTrue($salon->booking_confirmations);
    }

    public function test_settings_uploads_business_logo_and_persists_path(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $user->salon()->create([
            'name' => 'Studio',
            'plan' => 'free',
            'business_type' => 'salon-beauty',
            'mode' => Salon::MODE_APPOINTMENT,
        ]);

        $this->actingAs($user)->from('/dashboard/settings')->post('/settings', $this->validSettingsPayload([
            'logo' => UploadedFile::fake()->image('logo.png', 256, 256),
        ]))->assertRedirect();

        $salon = $user->salon->refresh();

        $this->assertNotNull($salon->logo_path);
        $this->assertStringStartsWith('logos/', $salon->logo_path);
        Storage::disk('public')->assertExists($salon->logo_path);
    }

    public function test_settings_derives_localization_from_country(): void
    {
        $user = User::factory()->create();
        $user->salon()->create([
            'name' => 'Studio',
            'plan' => 'free',
            'business_type' => 'salon-beauty',
            'mode' => Salon::MODE_APPOINTMENT,
        ]);

        $this->actingAs($user)->from('/dashboard/settings')->post('/settings', $this->validSettingsPayload([
            'country' => 'UK',
            'timezone' => 'Europe/London',
            'date_format' => 'dd/mm/yyyy',
        ]))->assertRedirect();

        $salon = $user->salon->refresh();

        $this->assertSame('GB', $salon->country);
        $this->assertSame('GBP', $salon->currency);
        $this->assertSame('+44', $salon->phone_prefix);
        $this->assertSame('Europe/London', $salon->timezone);
        $this->assertSame('dd/mm/yyyy', $salon->date_format);
    }

    public function test_settings_rejects_unsupported_timezone_and_date_format(): void
    {
        $user = User::factory()->create();
        $user->salon()->create([
            'name' => 'Studio',
            'business_type' => 'salon-beauty',
            'mode' => Salon::MODE_APPOINTMENT,
        ]);

        $this->actingAs($user)->from('/dashboard/settings')->post('/settings', $this->validSettingsPayload([
            'timezone' => 'America/New_York',
            'date_format' => 'mm-dd-yyyy',
        ]))
            ->assertRedirect('/dashboard/settings')
            ->assertSessionHasErrors(['timezone', 'date_format']);
    }

    public function test_missed_call_alerts_require_available_phone_ai(): void
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

    public function test_missed_call_alerts_stay_disabled_while_phone_ai_is_planned(): void
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

        $this->assertFalse($user->salon->refresh()->missed_call_alerts);
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

    public function test_dashboard_settings_receives_localization_options(): void
    {
        $user = User::factory()->create();
        $user->salon()->create([
            'name' => 'Studio',
            'business_type' => 'salon-beauty',
            'country' => null,
            'currency' => null,
            'phone_prefix' => null,
            'mode' => Salon::MODE_APPOINTMENT,
        ]);

        $this->actingAs($user)->get('/dashboard/settings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('localization.countries.0.code', 'RO')
                ->where('localization.countries.0.currency', 'RON')
                ->where('localization.countries.1.code', 'GB')
                ->where('localization.countries.1.phone_prefix', '+44')
                ->where('localization.timezones.0', 'Europe/Bucharest')
                ->where('localization.date_formats.0', 'dd.mm.yyyy')
            );
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
            'date_format' => 'dd/mm/yyyy',
        ], $overrides);
    }
}
