<?php

namespace Tests\Feature;

use App\Models\Salon;
use App\Models\User;
use App\Services\Billing\StripeBillingGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StripeBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'stripe.key' => 'pk_test_yougo',
            'stripe.secret' => 'sk_test_yougo',
            'stripe.webhook_secret' => 'whsec_yougo',
            'stripe.prices.website_chat' => 'price_website_chat',
            'stripe.prices.chat_whatsapp' => 'price_chat_whatsapp',
            'stripe.prices.voice_starter' => 'price_voice_starter',
            'stripe.prices.voice_growth' => 'price_voice_growth',
            'stripe.prices.voice_pro' => 'price_voice_pro',
        ]);
    }

    public function test_checkout_requires_auth(): void
    {
        $this->postJson('/dashboard/billing/checkout', ['plan_key' => 'website_chat'])
            ->assertUnauthorized();
    }

    public function test_checkout_rejects_free_and_unknown_plan(): void
    {
        [, $user] = $this->createSalonWithUser();

        $this->actingAs($user)->postJson('/dashboard/billing/checkout', ['plan_key' => 'free'])
            ->assertUnprocessable();

        $this->actingAs($user)->postJson('/dashboard/billing/checkout', ['plan_key' => 'unknown'])
            ->assertUnprocessable();
    }

    public function test_checkout_rejects_paid_plan_with_missing_price_id(): void
    {
        config(['stripe.prices.website_chat' => null]);
        [, $user] = $this->createSalonWithUser();

        $this->actingAs($user)->postJson('/dashboard/billing/checkout', ['plan_key' => 'website_chat'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('plan_key');
    }

    public function test_checkout_creates_session_with_correct_price_and_stores_customer(): void
    {
        [$salon, $user] = $this->createSalonWithUser();
        $fake = new FakeStripeBillingGateway();
        $this->app->instance(StripeBillingGateway::class, $fake);

        $this->actingAs($user)->postJson('/dashboard/billing/checkout', ['plan_key' => 'voice_growth'])
            ->assertOk()
            ->assertJson(['url' => 'https://checkout.stripe.test/session']);

        $this->assertSame('cus_created', $salon->refresh()->stripe_customer_id);
        $this->assertSame('price_voice_growth', $fake->checkoutPriceId);
        $this->assertSame('voice_growth', $fake->checkoutMetadata['plan_key']);
        $this->assertArrayNotHasKey('billing_cycle', $fake->checkoutMetadata);
        $this->assertArrayNotHasKey('annual', config('stripe.prices'));
    }

    public function test_portal_requires_auth_and_fails_without_customer(): void
    {
        $this->postJson('/dashboard/billing/portal')->assertUnauthorized();

        [, $user] = $this->createSalonWithUser();
        $this->actingAs($user)->postJson('/dashboard/billing/portal')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('subscription');
    }

    public function test_portal_returns_stripe_portal_url(): void
    {
        [, $user] = $this->createSalonWithUser(['stripe_customer_id' => 'cus_existing']);
        $fake = new FakeStripeBillingGateway();
        $this->app->instance(StripeBillingGateway::class, $fake);

        $this->actingAs($user)->postJson('/dashboard/billing/portal')
            ->assertOk()
            ->assertJson(['url' => 'https://billing.stripe.test/session']);
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        $this->postJson('/stripe/webhook', ['type' => 'customer.subscription.updated'], ['Stripe-Signature' => 'bad'])
            ->assertStatus(400);
    }

    public function test_checkout_completed_updates_salon_fields_and_plan(): void
    {
        [$salon] = $this->createSalonWithUser(['plan' => 'free', 'stripe_customer_id' => 'cus_123']);
        $fake = new FakeStripeBillingGateway();
        $fake->subscription = $this->subscriptionPayload($salon, 'sub_123', 'price_voice_starter', 'active');
        $this->app->instance(StripeBillingGateway::class, $fake);

        $this->postStripeWebhook([
            'id' => 'evt_checkout',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_123',
                'customer' => 'cus_123',
                'subscription' => 'sub_123',
                'metadata' => ['salon_id' => (string) $salon->id, 'plan_key' => 'voice_starter'],
            ]],
        ])->assertOk();

        $salon->refresh();
        $this->assertSame('voice_starter', $salon->plan);
        $this->assertSame('sub_123', $salon->stripe_subscription_id);
        $this->assertSame('price_voice_starter', $salon->stripe_price_id);
        $this->assertSame('active', $salon->subscription_status);
        $this->assertNotNull($salon->subscription_current_period_end);
    }

    public function test_subscription_updated_maps_price_id_to_internal_plan_key(): void
    {
        [$salon] = $this->createSalonWithUser(['stripe_customer_id' => 'cus_123']);

        $this->postStripeWebhook([
            'id' => 'evt_subscription_updated',
            'type' => 'customer.subscription.updated',
            'data' => ['object' => $this->subscriptionPayload($salon, 'sub_456', 'price_voice_pro', 'active')],
        ])->assertOk();

        $this->assertSame('voice_pro', $salon->refresh()->plan);
    }

    public function test_subscription_deleted_downgrades_to_free(): void
    {
        [$salon] = $this->createSalonWithUser([
            'plan' => 'voice_pro',
            'stripe_customer_id' => 'cus_123',
            'stripe_subscription_id' => 'sub_456',
            'stripe_price_id' => 'price_voice_pro',
        ]);

        $this->postStripeWebhook([
            'id' => 'evt_subscription_deleted',
            'type' => 'customer.subscription.deleted',
            'data' => ['object' => $this->subscriptionPayload($salon, 'sub_456', 'price_voice_pro', 'canceled')],
        ])->assertOk();

        $salon->refresh();
        $this->assertSame('free', $salon->plan);
        $this->assertNull($salon->stripe_subscription_id);
        $this->assertSame('canceled', $salon->subscription_status);
    }

    public function test_invoice_payment_failed_marks_problem_without_downgrading(): void
    {
        [$salon] = $this->createSalonWithUser([
            'plan' => 'voice_growth',
            'stripe_customer_id' => 'cus_123',
            'stripe_subscription_id' => 'sub_456',
        ]);

        $this->postStripeWebhook([
            'id' => 'evt_invoice_failed',
            'type' => 'invoice.payment_failed',
            'data' => ['object' => ['customer' => 'cus_123', 'subscription' => 'sub_456']],
        ])->assertOk();

        $salon->refresh();
        $this->assertSame('voice_growth', $salon->plan);
        $this->assertSame('payment_failed', $salon->subscription_status);
    }

    public function test_unknown_price_id_does_not_upgrade_plan_and_repeated_webhook_is_safe(): void
    {
        [$salon] = $this->createSalonWithUser(['plan' => 'free', 'stripe_customer_id' => 'cus_123']);
        $event = [
            'id' => 'evt_unknown_price',
            'type' => 'customer.subscription.updated',
            'data' => ['object' => $this->subscriptionPayload($salon, 'sub_789', 'price_unknown', 'active')],
        ];

        $this->postStripeWebhook($event)->assertOk();
        $this->postStripeWebhook($event)->assertOk();

        $this->assertSame('free', $salon->refresh()->plan);
    }

    public function test_billing_page_receives_stripe_state_and_frontend_contains_controls(): void
    {
        [, $user] = $this->createSalonWithUser([
            'plan' => 'voice_starter',
            'stripe_customer_id' => 'cus_123',
            'stripe_subscription_id' => 'sub_123',
            'subscription_status' => 'past_due',
        ]);

        $this->actingAs($user)->get('/dashboard/billing?checkout=success')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('billing.stripe.stripe_customer_exists', true)
                ->where('billing.stripe.stripe_subscription_exists', true)
                ->where('billing.stripe.payment_warning', true)
                ->where('billing.stripe.configured_prices.voice_starter', true)
            );

        $dashboard = file_get_contents(resource_path('js/Pages/Dashboard/Index.tsx'));
        $this->assertStringContainsString('/dashboard/billing/checkout', $dashboard);
        $this->assertStringContainsString('/dashboard/billing/portal', $dashboard);
        $this->assertStringContainsString('checkoutSuccess', $dashboard);
        $this->assertStringContainsString('checkoutCancelled', $dashboard);
    }

    private function createSalonWithUser(array $attributes = []): array
    {
        $user = User::factory()->create();
        $salon = $user->salon()->create(array_merge(['name' => 'YouGo Studio'], $attributes));

        return [$salon, $user];
    }

    private function subscriptionPayload(Salon $salon, string $subscriptionId, string $priceId, string $status): array
    {
        return [
            'id' => $subscriptionId,
            'customer' => $salon->stripe_customer_id ?? 'cus_123',
            'status' => $status,
            'current_period_end' => now()->addMonth()->timestamp,
            'metadata' => ['salon_id' => (string) $salon->id],
            'items' => ['data' => [[
                'price' => ['id' => $priceId],
            ]]],
        ];
    }

    private function postStripeWebhook(array $event)
    {
        $payload = json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", (string) config('stripe.webhook_secret'));

        return $this->call('POST', '/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $payload);
    }
}

class FakeStripeBillingGateway extends StripeBillingGateway
{
    public ?string $checkoutPriceId = null;

    /** @var array<string, string> */
    public array $checkoutMetadata = [];

    /** @var array<string, mixed> */
    public array $subscription = [];

    public function createCustomer(Salon $salon, User $user): array
    {
        return ['id' => 'cus_created'];
    }

    public function createCheckoutSession(string $customerId, string $priceId, string $successUrl, string $cancelUrl, array $metadata): array
    {
        $this->checkoutPriceId = $priceId;
        $this->checkoutMetadata = $metadata;

        return ['url' => 'https://checkout.stripe.test/session'];
    }

    public function createPortalSession(string $customerId, string $returnUrl): array
    {
        return ['url' => 'https://billing.stripe.test/session'];
    }

    public function retrieveSubscription(string $subscriptionId): object
    {
        return json_decode(json_encode($this->subscription));
    }
}
