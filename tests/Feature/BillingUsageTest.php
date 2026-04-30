<?php

namespace Tests\Feature;

use App\Models\Salon;
use App\Models\User;
use App\Services\Booking\BookingCreator;
use App\Services\Usage\UsageLimitService;
use App\Services\Usage\UsageTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BillingUsageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 4, 29, 10, 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_new_salon_defaults_to_free_plan_and_plans_load(): void
    {
        $salon = $this->createSalon();

        $this->assertSame('free', $salon->plan);
        $this->assertSame(['free', 'connect', 'voice', 'enterprise'], array_keys(config('yougo_plans')));
        $this->assertArrayNotHasKey('starter', config('yougo_plans'));
        $this->assertArrayNotHasKey('growth', config('yougo_plans'));
        $this->assertArrayNotHasKey('pro', config('yougo_plans'));
        $this->assertTrue(config('yougo_plans.connect.recommended'));
        $this->assertContains('AI booking requests', config('yougo_plans.free.features'));
        $this->assertContains('Dashboard access', config('yougo_plans.free.features'));
    }

    public function test_temporary_plan_selector_validates_and_updates_plan(): void
    {
        [$salon, $user] = $this->createSalonWithUser();

        $this->actingAs($user)->put('/billing/plan', ['plan' => 'not-a-plan'])
            ->assertSessionHasErrors('plan');
        $this->actingAs($user)->put('/billing/plan', ['plan' => 'growth'])
            ->assertSessionHasErrors('plan');

        $this->actingAs($user)->put('/billing/plan', ['plan' => 'connect'])
            ->assertRedirect();

        $this->assertSame('connect', $salon->refresh()->plan);
        $this->assertNotNull($salon->plan_started_at);

        $this->actingAs($user)->put('/billing/plan', ['plan' => 'voice'])
            ->assertRedirect();
        $this->assertSame('voice', $salon->refresh()->plan);

        $this->actingAs($user)->put('/billing/plan', ['plan' => 'enterprise'])
            ->assertRedirect();
        $this->assertSame('enterprise', $salon->refresh()->plan);
    }

    public function test_preview_chat_does_not_record_billable_messages_but_widget_does(): void
    {
        config(['services.gemini.key' => 'test-key']);
        Http::fake(['*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'Salut, te pot ajuta.']]],
            ]],
        ], 200)]);

        $salon = $this->createSalon();

        $this->postJson("/assistant/{$salon->id}/chat", [
            'messages' => [['role' => 'user', 'content' => 'Buna']],
        ])->assertOk();

        $this->assertSame(0, $salon->usageEvents()->where('event_type', 'conversation_started')->count());
        $this->assertSame(0, $salon->usageEvents()->where('event_type', 'user_message')->count());
        $this->assertSame(0, $salon->usageEvents()->where('event_type', 'ai_message')->count());

        $this->postJson("/widget/{$salon->widget_key}/chat", [
            'messages' => [['role' => 'user', 'content' => 'Buna']],
        ])->assertOk();

        $this->assertSame(1, $salon->usageEvents()->where('event_type', 'conversation_started')->count());
        $this->assertSame(1, $salon->usageEvents()->where('event_type', 'user_message')->count());
        $this->assertSame(1, $salon->usageEvents()->where('event_type', 'ai_message')->count());
    }

    public function test_usage_summary_counts_current_month_only(): void
    {
        $salon = $this->createSalon();
        $tracker = app(UsageTracker::class);

        $tracker->record($salon, 'conversation_started');
        $salon->usageEvents()->create([
            'event_type' => 'conversation_started',
            'quantity' => 1,
            'occurred_at' => '2026-03-29 10:00:00',
        ]);

        $summary = app(UsageLimitService::class)->usageSummary($salon);

        $this->assertSame(1, $summary['usage']['conversations']);
    }

    public function test_enterprise_null_limits_are_unlimited_and_connect_voice_limits_are_used(): void
    {
        $enterprise = $this->createSalon(['plan' => 'enterprise']);
        app(UsageTracker::class)->record($enterprise, 'conversation_started', 10000);
        app(UsageTracker::class)->record($enterprise, 'ai_message', 10000);
        app(UsageTracker::class)->record($enterprise, 'booking_created', 10000);

        $limits = app(UsageLimitService::class);

        $this->assertTrue($limits->canStartConversation($enterprise));
        $this->assertTrue($limits->canSendAiMessage($enterprise));
        $this->assertTrue($limits->canCreateBooking($enterprise));
        $this->assertNull($limits->usageSummary($enterprise)['limits']['conversations']);

        $connect = $this->createSalon(['plan' => 'connect']);
        $voice = $this->createSalon(['plan' => 'voice']);

        $this->assertSame(500, $limits->usageSummary($connect)['limits']['conversations']);
        $this->assertSame(1500, $limits->usageSummary($voice)['limits']['conversations']);
    }

    public function test_ai_message_limit_applies_to_widget_not_preview_chat(): void
    {
        config(['services.gemini.key' => 'test-key']);
        config(['yougo_plans.free.monthly_conversations' => 10]);
        config(['yougo_plans.free.monthly_ai_messages' => 0]);
        Http::fake(['*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'Salut, te pot ajuta.']]],
            ]],
        ], 200)]);

        $salon = $this->createSalon(['display_language' => 'en']);

        $this->postJson("/widget/{$salon->widget_key}/chat", [
            'messages' => [['role' => 'user', 'content' => 'Buna']],
        ])->assertOk()
            ->assertJsonPath('message', UsageLimitService::LIMIT_MESSAGE_EN);

        $this->postJson("/assistant/{$salon->id}/chat", [
            'messages' => [['role' => 'user', 'content' => 'Buna']],
        ])->assertOk()
            ->assertJsonPath('message', 'Salut, te pot ajuta.');
    }

    public function test_booking_limit_blocks_ai_booking_without_crashing(): void
    {
        config(['yougo_plans.free.monthly_bookings' => 1]);

        $salon = $this->createSalon(['display_language' => 'en']);
        $this->createBookableSetup($salon);
        app(UsageTracker::class)->record($salon, 'booking_created');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage(UsageLimitService::LIMIT_MESSAGE_EN);

        app(BookingCreator::class)->createFromAiFunctionCall($salon, [
            'client_name' => 'Ana Pop',
            'client_phone' => '0700000000',
            'location_id' => $salon->locations()->first()->id,
            'service_id' => $salon->services()->first()->id,
            'date' => '2026-04-30',
            'time' => '10:00',
        ], 'ai_assistant');
    }

    public function test_booking_usage_and_limit_apply_to_widget_not_preview_chat(): void
    {
        config(['services.gemini.key' => 'test-key']);
        config(['yougo_plans.free.monthly_bookings' => 0]);

        $salon = $this->createSalon(['display_language' => 'en']);
        $this->createBookableSetup($salon);
        $location = $salon->locations()->first();
        $service = $salon->services()->first();

        Http::fake(['*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [[
                    'functionCall' => [
                        'name' => 'bookBooking',
                        'args' => [
                            'client_name' => 'Ana Pop',
                            'client_phone' => '0700000000',
                            'location_id' => (string) $location->id,
                            'service_id' => (string) $service->id,
                            'date' => '2026-04-30',
                            'time' => '10:00',
                        ],
                    ],
                ]]],
            ]],
        ], 200)]);

        $this->postJson("/assistant/{$salon->id}/chat", [
            'messages' => [['role' => 'user', 'content' => 'Book me']],
        ])->assertOk()
            ->assertJsonStructure(['booking']);

        $this->assertSame(1, $salon->bookings()->count());
        $this->assertSame(0, $salon->usageEvents()->where('event_type', 'booking_created')->count());

        $this->postJson("/widget/{$salon->widget_key}/chat", [
            'messages' => [['role' => 'user', 'content' => 'Book me']],
        ])->assertOk()
            ->assertJsonPath('message', UsageLimitService::LIMIT_MESSAGE_EN);

        $this->assertSame(1, $salon->bookings()->count());
        $this->assertSame(0, $salon->usageEvents()->where('event_type', 'booking_created')->count());
    }

    public function test_conversation_limit_applies_to_widget_not_preview_chat(): void
    {
        config(['services.gemini.key' => 'test-key']);
        config(['yougo_plans.free.monthly_conversations' => 0]);
        Http::fake(['*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'Salut, te pot ajuta.']]],
            ]],
        ], 200)]);
        $salon = $this->createSalon(['display_language' => 'en']);

        $this->postJson("/assistant/{$salon->id}/chat", [
            'messages' => [['role' => 'user', 'content' => 'Buna']],
        ])->assertOk()
            ->assertJsonPath('message', 'Salut, te pot ajuta.');

        $this->assertSame(0, $salon->usageEvents()->where('event_type', 'conversation_started')->count());

        $this->postJson("/widget/{$salon->widget_key}/chat", [
            'messages' => [['role' => 'user', 'content' => 'Buna']],
        ])->assertOk()->assertJsonPath('message', UsageLimitService::LIMIT_MESSAGE_EN);
    }

    public function test_billing_dashboard_overview_usage_and_landing_pricing_load(): void
    {
        [$salon, $user] = $this->createSalonWithUser();
        app(UsageTracker::class)->record($salon, 'conversation_started');

        $this->actingAs($user)->get('/dashboard/billing')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Index')
                ->where('section', 'billing')
                ->where('billing.summary.usage.conversations', 1)
                ->where('billing.summary.plan.key', 'free')
            );

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('overview.usage')
                ->where('overview.usage.usage.conversations', 1)
            );

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Landing')
                ->where('plans.0.key', 'free')
                ->where('plans.1.key', 'connect')
                ->where('plans.1.recommended', true)
                ->where('plans.2.key', 'voice')
                ->where('plans.2.phone_minute_price_label', '2.50 RON/minut')
                ->where('plans.3.key', 'enterprise')
            );
    }

    private function createSalon(array $attributes = []): Salon
    {
        return $this->createSalonWithUser($attributes)[0];
    }

    private function createSalonWithUser(array $attributes = []): array
    {
        $user = User::factory()->create();
        $salon = $user->salon()->create(array_merge([
            'name' => 'YouGo Studio',
        ], $attributes));

        return [$salon, $user];
    }

    private function createBookableSetup(Salon $salon): void
    {
        $location = $salon->locations()->create([
            'name' => 'Central',
            'address' => 'Main Street',
            'hours' => ['thu' => '10:00 - 18:00'],
        ]);

        $salon->services()->create([
            'name' => 'Tuns',
            'price' => '100',
            'duration' => 30,
            'location_ids' => [$location->id],
        ]);
    }
}
