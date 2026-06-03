<?php

namespace Tests\Feature;

use App\Mail\WhatsappSetupRequestMail;
use App\Models\PlatformAdmin;
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
        $this->get('/platform-admin')->assertRedirect('/platform-admin/login');
    }

    public function test_platform_admin_login_page_loads_for_guests(): void
    {
        $this->get('/platform-admin/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('PlatformAdmin/Login'));
    }

    public function test_platform_admin_login_redirects_existing_admin_to_admin_overview(): void
    {
        $admin = $this->createPlatformAdmin();

        $this->actingAs($admin, 'platform_admin')
            ->get('/platform-admin/login')
            ->assertRedirect('/platform-admin');
    }

    public function test_normal_business_user_cannot_access_platform_admin_routes(): void
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
            '/platform-admin/settings',
        ] as $route) {
            $this->actingAs($user)->get($route)->assertRedirect('/platform-admin/login');
        }
    }

    public function test_platform_admin_can_access_all_platform_admin_pages(): void
    {
        $admin = $this->createPlatformAdmin();
        $salon = $this->createSalon();
        $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => WhatsappIntegration::STATUS_REQUESTED,
            'requested_number' => '+40711111111',
            'requested_at' => now(),
        ]);

        $routes = [
            '/platform-admin' => 'PlatformAdmin/Overview',
            '/platform-admin/businesses' => 'PlatformAdmin/Businesses',
            "/platform-admin/businesses/{$salon->id}" => 'PlatformAdmin/BusinessDetail',
            '/platform-admin/whatsapp-onboarding' => 'PlatformAdmin/WhatsappOnboarding',
            '/platform-admin/usage' => 'PlatformAdmin/Usage',
            '/platform-admin/issues' => 'PlatformAdmin/Issues',
            '/platform-admin/settings' => 'PlatformAdmin/Settings',
        ];

        foreach ($routes as $route => $component) {
            $this->actingAs($admin, 'platform_admin')
                ->get($route)
                ->assertOk()
                ->assertInertia(function (Assert $page) use ($component): void {
                    $page->component($component);

                    if ($component !== 'PlatformAdmin/Settings') {
                        $page->has('payload');
                    }
                });
        }
    }

    public function test_deleted_business_account_disappears_from_platform_admin(): void
    {
        $admin = $this->createPlatformAdmin();
        $user = User::factory()->create();
        $salon = $this->createSalon([
            'user_id' => $user->id,
            'name' => 'Deleted Studio',
        ]);

        $this->actingAs($user)
            ->delete('/account')
            ->assertRedirect(route('home'));

        $this->actingAs($admin, 'platform_admin')
            ->get('/platform-admin')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('payload.totals.businesses', 0)
                ->where('payload.recent_businesses', []));

        $this->actingAs($admin, 'platform_admin')
            ->get('/platform-admin/businesses')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('payload.items', [])
                ->where('payload.pagination.total', 0));

        $this->actingAs($admin, 'platform_admin')
            ->get("/platform-admin/businesses/{$salon->id}")
            ->assertNotFound();
    }

    public function test_normal_business_user_cannot_login_through_platform_admin_login(): void
    {
        $user = User::factory()->create([
            'email' => 'business@example.com',
        ]);

        $this->post('/platform-admin/login', [
            'username' => $user->email,
            'password' => 'password',
        ])
            ->assertSessionHasErrors([
                'username' => 'These credentials do not have platform admin access.',
            ]);

        $this->assertGuest('platform_admin');
    }

    public function test_platform_admin_can_login_through_platform_admin_login(): void
    {
        $admin = $this->createPlatformAdmin(['username' => 'operator']);

        $this->post('/platform-admin/login', [
            'username' => $admin->username,
            'password' => 'password',
        ])
            ->assertRedirect('/platform-admin');

        $this->assertAuthenticatedAs($admin, 'platform_admin');
    }

    public function test_default_platform_admin_credentials_are_created_by_migration(): void
    {
        $this->post('/platform-admin/login', [
            'username' => 'admin',
            'password' => 'admin',
        ])
            ->assertRedirect('/platform-admin');

        $this->assertAuthenticated('platform_admin');
    }

    public function test_business_login_still_works_for_normal_users(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@example.com',
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_platform_admin_logout_logs_out_and_redirects_to_platform_login(): void
    {
        $admin = $this->createPlatformAdmin();

        $this->actingAs($admin, 'platform_admin')
            ->post('/platform-admin/logout')
            ->assertRedirect('/platform-admin/login');

        $this->assertGuest('platform_admin');
    }

    public function test_platform_admin_can_update_dedicated_credentials(): void
    {
        $admin = $this->createPlatformAdmin(['username' => 'old-admin']);

        $this->actingAs($admin, 'platform_admin')
            ->put('/platform-admin/settings', [
                'name' => 'Operations Lead',
                'username' => 'new-admin',
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertRedirect();

        $admin->refresh();
        $this->assertSame('Operations Lead', $admin->name);
        $this->assertSame('new-admin', $admin->username);

        $this->post('/platform-admin/logout');

        $this->post('/platform-admin/login', [
            'username' => 'new-admin',
            'password' => 'new-password',
        ])
            ->assertRedirect('/platform-admin');

        $this->assertAuthenticatedAs($admin, 'platform_admin');
    }

    public function test_make_platform_admin_command_creates_or_updates_dedicated_admin(): void
    {
        $this->artisan('yougo:make-platform-admin operator --password=secret-password')
            ->expectsOutput('Platform Admin account operator is ready.')
            ->assertSuccessful();

        $admin = PlatformAdmin::query()->where('username', 'operator')->firstOrFail();

        $this->post('/platform-admin/login', [
            'username' => $admin->username,
            'password' => 'secret-password',
        ])
            ->assertRedirect('/platform-admin');
    }

    public function test_whatsapp_requested_integrations_appear_in_onboarding_queue(): void
    {
        $admin = $this->createPlatformAdmin();
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

        $this->actingAs($admin, 'platform_admin')
            ->get('/platform-admin/whatsapp-onboarding')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('PlatformAdmin/WhatsappOnboarding')
                ->where('payload.items.0.business_name', 'Belle')
                ->where('payload.items.0.requested_number', '+40711111111')
                ->where('payload.items.0.activation_command', "php artisan yougo:whatsapp-activate {$salon->id} whatsapp:+40711111111"));
    }

    public function test_requested_without_setup_details_does_not_appear_in_onboarding_queue(): void
    {
        $admin = $this->createPlatformAdmin();
        $salon = $this->createSalon(['name' => 'No Setup Details']);
        $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => WhatsappIntegration::STATUS_REQUESTED,
            'requested_number' => '+40711111111',
            'requested_at' => now(),
        ]);

        $this->actingAs($admin, 'platform_admin')
            ->get('/platform-admin/whatsapp-onboarding')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('PlatformAdmin/WhatsappOnboarding')
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
        $admin = $this->createPlatformAdmin();
        $salon = $this->createSalon();
        $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => WhatsappIntegration::STATUS_REQUESTED,
            'requested_number' => '0040711111111',
            'twilio_sender' => null,
            'requested_at' => now(),
        ]);

        $this->actingAs($admin, 'platform_admin')
            ->get("/platform-admin/businesses/{$salon->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('payload.whatsapp.twilio_sender', null)
                ->where('payload.whatsapp.activation_command', "php artisan yougo:whatsapp-activate {$salon->id} whatsapp:+40711111111"));
    }

    public function test_technical_whatsapp_fields_are_absent_from_normal_dashboard_payload(): void
    {
        $user = User::factory()->create();
        $salon = $this->createSalon([
            'user_id' => $user->id,
            'plan' => 'chat_whatsapp',
        ]);
        $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => WhatsappIntegration::STATUS_ACTIVE,
            'requested_number' => '+40711111111',
            'display_number' => '+40711111111',
            'twilio_sender' => 'whatsapp:+40711111111',
            'ai_enabled' => true,
        ]);

        $this->actingAs($user)
            ->get('/dashboard/whatsapp')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->missing('salon.whatsapp_integration.twilio_sender')
                ->missing('billing.whatsapp_integration.twilio_sender'));
    }

    public function test_customer_facing_whatsapp_json_does_not_return_twilio_sender(): void
    {
        $user = User::factory()->create();
        $salon = $this->createSalon([
            'user_id' => $user->id,
            'plan' => 'chat_whatsapp',
        ]);
        $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => WhatsappIntegration::STATUS_ACTIVE,
            'requested_number' => '+40711111111',
            'display_number' => '+40711111111',
            'twilio_sender' => 'whatsapp:+40711111111',
            'ai_enabled' => false,
        ]);

        $this->actingAs($user)
            ->patchJson('/dashboard/whatsapp/toggle', ['ai_enabled' => true])
            ->assertOk()
            ->assertJsonMissingPath('integration.twilio_sender');
    }

    public function test_platform_admin_source_contains_required_navigation_and_status_labels(): void
    {
        $source = collect([
            'Components.tsx',
            'Overview.tsx',
            'Businesses.tsx',
            'BusinessDetail.tsx',
            'Login.tsx',
            'WhatsappOnboarding.tsx',
            'Usage.tsx',
            'Issues.tsx',
            'Settings.tsx',
        ])->map(fn (string $file) => file_get_contents(resource_path("js/Pages/PlatformAdmin/{$file}")))->implode("\n");

        $this->assertStringContainsString('Platform Admin', $source);
        $this->assertStringContainsString('WhatsApp Onboarding', $source);
        $this->assertStringContainsString('Usage', $source);
        $this->assertStringContainsString('Issues', $source);
        $this->assertStringContainsString('Phone AI is planned', $source);
        $this->assertStringContainsString('Copy WhatsApp activation command', $source);
        $this->assertStringContainsString('Sign in to Platform Admin', $source);
        $this->assertStringContainsString('Save admin credentials', $source);
    }

    private function createPlatformAdmin(array $attributes = []): PlatformAdmin
    {
        return PlatformAdmin::query()->create(array_merge([
            'name' => 'Platform Admin',
            'username' => 'operator-'.uniqid(),
            'password' => 'password',
        ], $attributes));
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
