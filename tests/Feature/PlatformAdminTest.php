<?php

namespace Tests\Feature;

use App\Mail\WhatsappSetupRequestMail;
use App\Models\Salon;
use App\Models\User;
use App\Models\WhatsappIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PlatformAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_platform_admin(): void
    {
        $this->get('/platform-admin')->assertRedirect('/login');
    }

    public function test_normal_business_user_gets_403_for_platform_admin_routes(): void
    {
        $user = User::factory()->create();
        $salon = $this->createSalon(['user_id' => $user->id]);

        foreach ([
            '/platform-admin',
            '/platform-admin/businesses',
            "/platform-admin/businesses/{$salon->id}",
            '/platform-admin/whatsapp-onboarding',
            '/platform-admin/usage',
            '/platform-admin/issues',
        ] as $route) {
            $this->actingAs($user)->get($route)->assertForbidden();
        }
    }

    public function test_platform_admin_can_access_all_platform_admin_pages(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $salon = $this->createSalon();
        $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => WhatsappIntegration::STATUS_REQUESTED,
            'requested_number' => '+40711111111',
            'requested_at' => now(),
        ]);

        $routes = [
            '/platform-admin' => 'overview',
            '/platform-admin/businesses' => 'businesses',
            "/platform-admin/businesses/{$salon->id}" => 'business_detail',
            '/platform-admin/whatsapp-onboarding' => 'whatsapp_onboarding',
            '/platform-admin/usage' => 'usage',
            '/platform-admin/issues' => 'issues',
        ];

        foreach ($routes as $route => $pageName) {
            $this->actingAs($admin)
                ->get($route)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('PlatformAdmin/Index')
                    ->where('page', $pageName)
                    ->has('payload'));
        }
    }

    public function test_make_platform_admin_command_promotes_existing_user(): void
    {
        $user = User::factory()->create(['email' => 'operator@example.com']);

        $this->artisan('yougo:make-platform-admin operator@example.com')
            ->assertSuccessful();

        $this->assertTrue($user->refresh()->is_platform_admin);
    }

    public function test_whatsapp_requested_integrations_appear_in_onboarding_queue(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $salon = $this->createSalon(['name' => 'Belle']);
        $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => WhatsappIntegration::STATUS_REQUESTED,
            'requested_number' => '+40711111111',
            'requested_at' => now(),
            'metadata' => [
                'latest_setup_request' => [
                    'contact_person' => 'Maria Owner',
                    'preferred_meeting_type' => 'video_call',
                ],
            ],
        ]);

        $this->actingAs($admin)
            ->get('/platform-admin/whatsapp-onboarding')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('PlatformAdmin/Index')
                ->where('payload.items.0.business_name', 'Belle')
                ->where('payload.items.0.requested_number', '+40711111111')
                ->where('payload.items.0.activation_command', "php artisan yougo:whatsapp-activate {$salon->id} whatsapp:+40711111111"));
    }

    public function test_requested_without_setup_details_does_not_appear_in_onboarding_queue(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $salon = $this->createSalon(['name' => 'No Setup Details']);
        $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => WhatsappIntegration::STATUS_REQUESTED,
            'requested_number' => '+40711111111',
            'requested_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get('/platform-admin/whatsapp-onboarding')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('PlatformAdmin/Index')
                ->where('payload.items', []));
    }

    public function test_setup_request_metadata_is_stored_without_password_or_code_fields(): void
    {
        Mail::fake();
        config(['mail.whatsapp_setup_request_to' => 'ops@example.com']);

        $user = User::factory()->create();
        $salon = $this->createSalon([
            'user_id' => $user->id,
            'plan' => 'chat_whatsapp',
        ]);
        $integration = $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => WhatsappIntegration::STATUS_REQUESTED,
            'requested_number' => '+40711111111',
            'requested_at' => now(),
            'metadata' => ['existing' => true],
        ]);

        $this->actingAs($user)
            ->postJson('/dashboard/whatsapp/setup-request', $this->validSetupRequest())
            ->assertOk();

        $latest = $integration->refresh()->metadata['latest_setup_request'];

        $this->assertSame('Maria Owner', $latest['contact_person']);
        $this->assertSame('video_call', $latest['preferred_meeting_type']);
        $this->assertSame('Tuesday after 14:00', $latest['preferred_availability']);
        $this->assertArrayNotHasKey('password', $latest);
        $this->assertArrayNotHasKey('two_factor_code', $latest);
        $this->assertTrue($integration->metadata['existing']);

        Mail::assertSent(WhatsappSetupRequestMail::class);
    }

    public function test_business_detail_payload_contains_technical_whatsapp_fields_for_admins(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $salon = $this->createSalon();
        $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => WhatsappIntegration::STATUS_REQUESTED,
            'requested_number' => '0040711111111',
            'twilio_sender' => null,
            'requested_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get("/platform-admin/businesses/{$salon->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('payload.whatsapp.twilio_sender', null)
                ->where('payload.whatsapp.activation_command', "php artisan yougo:whatsapp-activate {$salon->id} whatsapp:+40711111111"));
    }

    public function test_platform_admin_source_contains_required_navigation_and_status_labels(): void
    {
        $source = file_get_contents(resource_path('js/Pages/PlatformAdmin/Index.tsx'));

        $this->assertStringContainsString('Platform Admin', $source);
        $this->assertStringContainsString('WhatsApp Onboarding', $source);
        $this->assertStringContainsString('Usage', $source);
        $this->assertStringContainsString('Issues', $source);
        $this->assertStringContainsString('Phone AI is planned', $source);
        $this->assertStringContainsString('Copy WhatsApp activation command', $source);
    }

    private function createSalon(array $attributes = []): Salon
    {
        $user = isset($attributes['user_id']) ? null : User::factory()->create();

        return Salon::query()->create(array_merge([
            'user_id' => $user?->id,
            'name' => 'YouGo Studio',
            'plan' => 'chat_whatsapp',
            'subscription_status' => 'active',
        ], $attributes));
    }

    private function validSetupRequest(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'YouGo Studio',
            'contact_person' => 'Maria Owner',
            'contact_email' => 'owner@example.com',
            'contact_phone' => '+40711111111',
            'requested_whatsapp_number' => '+40722222222',
            'whatsapp_display_name' => 'YouGo Studio',
            'website_or_social_link' => 'https://example.com',
            'has_meta_business_account' => 'yes',
            'number_currently_used_on_whatsapp_app' => 'not_sure',
            'can_receive_sms_or_call' => 'yes',
            'preferred_meeting_type' => 'video_call',
            'preferred_availability' => 'Tuesday after 14:00',
            'notes' => 'Prefer English setup call.',
        ], $overrides);
    }
}
