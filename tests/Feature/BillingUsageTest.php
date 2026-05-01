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
        $this->assertSame(['free', 'website_chat', 'chat_whatsapp', 'voice_starter', 'voice_growth', 'voice_pro'], array_keys(config('yougo_plans')));
        $this->assertArrayNotHasKey('connect', config('yougo_plans'));
        $this->assertArrayNotHasKey('voice', config('yougo_plans'));
        $this->assertArrayNotHasKey('enterprise', config('yougo_plans'));
        $this->assertContains('AI booking requests', config('yougo_plans.free.features'));
        $this->assertContains('Dashboard access', config('yougo_plans.free.features'));
        $this->assertSame('0 RON', config('yougo_plans.free.price_label'));
        $this->assertSame(50, config('yougo_plans.free.monthly_conversations'));
        $this->assertSame(100, config('yougo_plans.free.monthly_ai_messages'));
        $this->assertSame(10, config('yougo_plans.free.monthly_bookings'));

        $this->assertSame('149 RON/lună', config('yougo_plans.website_chat.price_label'));
        $this->assertSame('299 RON/lună', config('yougo_plans.chat_whatsapp.price_label'));
        $this->assertSame('599 RON/lună', config('yougo_plans.voice_starter.price_label'));
        $this->assertSame('999 RON/lună', config('yougo_plans.voice_growth.price_label'));
        $this->assertSame('2.499 RON/lună', config('yougo_plans.voice_pro.price_label'));
        $this->assertSame('—', config('yougo_plans.website_chat.phone_minutes_label'));
        $this->assertSame('—', config('yougo_plans.chat_whatsapp.phone_minutes_label'));
        $this->assertSame('300 min', config('yougo_plans.voice_starter.phone_minutes_label'));
        $this->assertSame('1000 min', config('yougo_plans.voice_growth.phone_minutes_label'));
        $this->assertSame('3000 min', config('yougo_plans.voice_pro.phone_minutes_label'));
        $this->assertTrue(config('yougo_plans.voice_starter.recommended'));

        foreach (['website_chat', 'chat_whatsapp', 'voice_starter', 'voice_growth', 'voice_pro'] as $key) {
            $this->assertTrue(config("yougo_plans.{$key}.ai_bookings_enabled"));
            $this->assertContains('Programări AI', config("yougo_plans.{$key}.features"));
        }

        $this->assertFalse(config('yougo_plans.website_chat.phone_enabled'));
        $this->assertFalse(config('yougo_plans.chat_whatsapp.phone_enabled'));
        $this->assertTrue(config('yougo_plans.voice_starter.phone_enabled'));
        $this->assertTrue(config('yougo_plans.voice_growth.phone_enabled'));
        $this->assertTrue(config('yougo_plans.voice_pro.phone_enabled'));
    }

    public function test_temporary_plan_selector_validates_and_updates_plan(): void
    {
        [$salon, $user] = $this->createSalonWithUser();

        $this->actingAs($user)->put('/billing/plan', ['plan' => 'not-a-plan'])
            ->assertSessionHasErrors('plan');
        $this->actingAs($user)->put('/billing/plan', ['plan' => 'growth'])
            ->assertSessionHasErrors('plan');
        $this->actingAs($user)->put('/billing/plan', ['plan' => 'connect'])
            ->assertSessionHasErrors('plan');

        $this->actingAs($user)->put('/billing/plan', ['plan' => 'website_chat'])
            ->assertRedirect();

        $this->assertSame('website_chat', $salon->refresh()->plan);
        $this->assertNotNull($salon->plan_started_at);

        $this->actingAs($user)->put('/billing/plan', ['plan' => 'chat_whatsapp'])
            ->assertRedirect();
        $this->assertSame('chat_whatsapp', $salon->refresh()->plan);

        $this->actingAs($user)->put('/billing/plan', ['plan' => 'voice_starter'])
            ->assertRedirect();
        $this->assertSame('voice_starter', $salon->refresh()->plan);

        $this->actingAs($user)->put('/billing/plan', ['plan' => 'voice_growth'])
            ->assertRedirect();
        $this->assertSame('voice_growth', $salon->refresh()->plan);

        $this->actingAs($user)->put('/billing/plan', ['plan' => 'voice_pro'])
            ->assertRedirect();
        $this->assertSame('voice_pro', $salon->refresh()->plan);

        $this->actingAs($user)->put('/billing/plan', ['plan' => 'free'])
            ->assertRedirect();
        $this->assertSame('free', $salon->refresh()->plan);
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

    public function test_old_plan_keys_alias_to_new_limits_and_new_limits_are_used(): void
    {
        $enterprise = $this->createSalon(['plan' => 'enterprise']);
        app(UsageTracker::class)->record($enterprise, 'conversation_started', 50000);
        app(UsageTracker::class)->record($enterprise, 'ai_message', 50000);
        app(UsageTracker::class)->record($enterprise, 'booking_created', 50000);

        $limits = app(UsageLimitService::class);

        $this->assertFalse($limits->canStartConversation($enterprise));
        $this->assertFalse($limits->canSendAiMessage($enterprise));
        $this->assertFalse($limits->canCreateBooking($enterprise));
        $this->assertSame('voice_pro', $limits->usageSummary($enterprise)['plan']['key']);
        $this->assertSame(8000, $limits->usageSummary($enterprise)['limits']['conversations']);

        $connect = $this->createSalon(['plan' => 'connect']);
        $voice = $this->createSalon(['plan' => 'voice']);
        $websiteChat = $this->createSalon(['plan' => 'website_chat']);
        $growth = $this->createSalon(['plan' => 'voice_growth']);

        $this->assertSame('chat_whatsapp', $limits->usageSummary($connect)['plan']['key']);
        $this->assertSame('voice_starter', $limits->usageSummary($voice)['plan']['key']);
        $this->assertSame(1000, $limits->usageSummary($connect)['limits']['conversations']);
        $this->assertSame(1500, $limits->usageSummary($voice)['limits']['conversations']);
        $this->assertSame(500, $limits->usageSummary($websiteChat)['limits']['conversations']);
        $this->assertSame(3000, $limits->usageSummary($growth)['limits']['conversations']);
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
                ->where('billing.plans.0.key', 'free')
                ->where('billing.plans.1.key', 'website_chat')
                ->where('billing.plans.2.key', 'chat_whatsapp')
                ->where('billing.plans.3.key', 'voice_starter')
                ->where('billing.plans.4.key', 'voice_growth')
                ->where('billing.plans.5.key', 'voice_pro')
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
                ->where('plans.1.key', 'website_chat')
                ->where('plans.1.price_label', '149 RON/lună')
                ->where('plans.2.key', 'chat_whatsapp')
                ->where('plans.3.key', 'voice_starter')
                ->where('plans.3.recommended', true)
                ->where('plans.3.phone_minutes_label', '300 min')
                ->where('plans.4.key', 'voice_growth')
                ->where('plans.5.key', 'voice_pro')
            );
    }

    public function test_pricing_source_and_translations_cover_comparison_table(): void
    {
        $landing = file_get_contents(resource_path('js/Pages/Landing.tsx'));
        $dashboard = file_get_contents(resource_path('js/Pages/Dashboard/Index.tsx'));
        $translations = file_get_contents(resource_path('js/i18n.ts'));

        foreach (['price', 'websiteChat', 'whatsapp', 'phoneAi', 'aiBookings'] as $key) {
            $this->assertStringContainsString($key, $landing);
            $this->assertStringContainsString($key, $dashboard);
            $this->assertStringContainsString($key, $translations);
        }

        $this->assertStringNotContainsString("t('phoneMinutes')", $landing);
        $this->assertStringNotContainsString("t('phoneMinutes')", $dashboard);

        foreach (['planDescription_website_chat', 'planDescription_chat_whatsapp', 'planDescription_voice_starter', 'planDescription_voice_growth', 'planDescription_voice_pro'] as $key) {
            $this->assertStringContainsString($key, $translations);
        }

        $this->assertStringContainsString('Trimite notificări email pentru cererile noi', $translations);
        $this->assertStringContainsString('Sends email notifications for new requests', $translations);
        $this->assertStringContainsString('—', $landing);
        $this->assertStringContainsString('text-green-600', $landing);
        $this->assertStringNotContainsString('>Da<', $landing);
        $this->assertStringNotContainsString('>Nu<', $landing);
        $this->assertStringNotContainsString('>Yes<', $landing);
        $this->assertStringNotContainsString('>No<', $landing);
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
