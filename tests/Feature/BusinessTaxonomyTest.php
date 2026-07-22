<?php

namespace Tests\Feature;

use App\Models\Salon;
use App\Models\User;
use App\Support\BusinessLocalization;
use App\Support\BusinessTaxonomy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
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

    public function test_settings_can_update_password_with_old_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);
        $user->salon()->create([
            'name' => 'Studio',
            'business_type' => 'salon-beauty',
            'mode' => Salon::MODE_APPOINTMENT,
        ]);

        $this->actingAs($user)->from('/dashboard/settings')->post('/settings', $this->validSettingsPayload([
            'old_password' => 'old-password',
            'new_password' => 'new-secure-password',
        ]))->assertRedirect();

        $this->assertTrue(Hash::check('new-secure-password', $user->refresh()->password));
    }

    public function test_settings_rejects_password_update_with_wrong_old_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);
        $user->salon()->create([
            'name' => 'Studio',
            'business_type' => 'salon-beauty',
            'mode' => Salon::MODE_APPOINTMENT,
        ]);

        $this->actingAs($user)->from('/dashboard/settings')->post('/settings', $this->validSettingsPayload([
            'old_password' => 'wrong-password',
            'new_password' => 'new-secure-password',
        ]))->assertSessionHasErrors('old_password');

        $this->assertTrue(Hash::check('old-password', $user->refresh()->password));
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

    public function test_services_without_currency_override_follow_business_currency_after_country_change(): void
    {
        $user = User::factory()->create();
        $salon = $user->salon()->create([
            'name' => 'Studio',
            'plan' => 'free',
            'business_type' => 'salon-beauty',
            'mode' => Salon::MODE_APPOINTMENT,
            'country' => 'RO',
            'currency' => 'RON',
        ]);
        $service = $salon->services()->create([
            'name' => 'Consultatie',
            'price' => '100',
            'currency' => null,
            'duration' => 30,
        ]);

        $this->actingAs($user)->from('/dashboard/settings')->post('/settings', $this->validSettingsPayload([
            'country' => 'UK',
            'timezone' => 'Europe/London',
            'date_format' => 'dd/mm/yyyy',
        ]))->assertRedirect();

        $salon->refresh();
        $service->refresh();

        $this->assertSame('GBP', $salon->currency);
        $this->assertNull($service->currency);
        $this->assertSame('£100', BusinessLocalization::formatServicePrice($service->price, $salon, $service->currency));
    }

    public function test_settings_accepts_written_month_date_format(): void
    {
        $user = User::factory()->create();
        $user->salon()->create([
            'name' => 'Studio',
            'plan' => 'free',
            'business_type' => 'salon-beauty',
            'mode' => Salon::MODE_APPOINTMENT,
        ]);

        $this->actingAs($user)->from('/dashboard/settings')->post('/settings', $this->validSettingsPayload([
            'date_format' => 'dd month yyyy',
        ]))->assertRedirect();

        $this->assertSame('dd month yyyy', $user->salon->refresh()->date_format);
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

    public function test_dashboard_profile_source_contains_password_fields_on_same_desktop_grid(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Dashboard/Index.tsx'));
        $translations = file_get_contents(resource_path('js/i18n.ts'));

        $profileSource = substr(
            $source,
            strpos($source, "SettingsPanel icon={User} title={t('profile')}"),
            strpos($source, 'SettingsPanel icon={Globe2}') - strpos($source, "SettingsPanel icon={User} title={t('profile')}"),
        );

        $this->assertStringContainsString('md:grid-cols-2', $profileSource);
        $this->assertStringContainsString("t('oldPassword')", $profileSource);
        $this->assertStringContainsString("t('newPassword')", $profileSource);
        $this->assertStringContainsString('autoComplete="current-password"', $profileSource);
        $this->assertStringContainsString('autoComplete="new-password"', $profileSource);
        $this->assertStringContainsString('Old password', $translations);
        $this->assertStringContainsString('New password', $translations);
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

    public function test_every_business_type_exposes_the_full_capability_schema(): void
    {
        foreach (BusinessTaxonomy::all() as $businessType) {
            $slug = $businessType['slug'];

            $this->assertArrayHasKey('category', $businessType, "{$slug} missing category");
            $this->assertArrayHasKey('primary_capability', $businessType, "{$slug} missing primary_capability");
            $this->assertArrayHasKey('secondary_capabilities', $businessType, "{$slug} missing secondary_capabilities");
            $this->assertIsArray($businessType['secondary_capabilities']);
            $this->assertArrayHasKey('collected_fields', $businessType);
            $this->assertArrayHasKey('required_fields', $businessType);
            $this->assertArrayHasKey('conditional_fields', $businessType);
            $this->assertArrayHasKey('urgency_rules', $businessType);
            $this->assertArrayHasKey('safety_instructions', $businessType);
            $this->assertArrayHasKey('dashboard_labels', $businessType);
            $this->assertArrayHasKey('recommended_modules', $businessType);
            $this->assertArrayHasKey('capability_availability', $businessType);
            $this->assertArrayHasKey('aliases', $businessType);

            $this->assertContains($businessType['primary_capability'], ['appointment', 'request'], "{$slug} primary_capability must be an already-implemented capability");
            $this->assertNotContains('reservation', $businessType['secondary_capabilities'], "{$slug} cannot recommend reservation as secondary yet");
            $this->assertFalse($businessType['capability_availability']['reservation'], "{$slug} reservation must never be marked available");
        }
    }

    public function test_home_services_business_type_recommends_request_primary(): void
    {
        $homeServices = BusinessTaxonomy::findBusinessType('home-services');

        $this->assertNotNull($homeServices);
        $this->assertSame('request', $homeServices['primary_capability']);
        $this->assertContains('appointment', $homeServices['secondary_capabilities']);
        $this->assertNotNull($homeServices['safety_instructions']);
    }

    public function test_auto_service_business_type_recommends_request_primary_with_appointment_secondary(): void
    {
        $autoService = BusinessTaxonomy::findBusinessType('auto-service');

        $this->assertSame('request', $autoService['primary_capability']);
        $this->assertSame(['appointment'], $autoService['secondary_capabilities']);
    }

    public function test_salon_beauty_business_type_recommends_appointment_primary(): void
    {
        $salonBeauty = BusinessTaxonomy::findBusinessType('salon-beauty');

        $this->assertSame('appointment', $salonBeauty['primary_capability']);
        $this->assertContains('request', $salonBeauty['secondary_capabilities']);
    }
}
