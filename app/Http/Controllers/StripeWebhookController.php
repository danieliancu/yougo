<?php

namespace App\Http\Controllers;

use App\Models\Salon;
use App\Services\Billing\StripeBillingGateway;
use App\Support\StripePlans;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Throwable;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeBillingGateway $stripe)
    {
        $payload = $request->getContent();
        $signature = $request->header('Stripe-Signature', '');
        $secret = (string) config('stripe.webhook_secret');

        try {
            Webhook::constructEvent($payload, $signature, $secret);
        } catch (UnexpectedValueException|SignatureVerificationException $e) {
            Log::warning('Rejected Stripe webhook signature.', ['error' => $e->getMessage()]);

            return response('Invalid Stripe signature.', 400);
        }

        $event = json_decode($payload, true);
        if (! is_array($event)) {
            return response('Invalid Stripe event.', 400);
        }

        try {
            match ($event['type'] ?? '') {
                'checkout.session.completed' => $this->handleCheckoutCompleted($event, $stripe),
                'customer.subscription.created',
                'customer.subscription.updated' => $this->handleSubscriptionUpdated($event),
                'customer.subscription.deleted' => $this->handleSubscriptionDeleted($event),
                'invoice.payment_succeeded' => $this->handleInvoicePaymentSucceeded($event),
                'invoice.payment_failed' => $this->handleInvoicePaymentFailed($event),
                default => null,
            };
        } catch (Throwable $e) {
            Log::error('Stripe webhook processing failed.', [
                'type' => $event['type'] ?? 'unknown',
                'stripe_event_id' => $event['id'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }

        return response('OK');
    }

    private function handleCheckoutCompleted(array $event, StripeBillingGateway $stripe): void
    {
        $session = data_get($event, 'data.object', []);
        $salon = $this->findSalon(
            data_get($session, 'subscription'),
            data_get($session, 'metadata.salon_id'),
            data_get($session, 'customer'),
        );

        if (! $salon) {
            Log::warning('Stripe checkout completed for unknown salon.', ['session_id' => data_get($session, 'id')]);

            return;
        }

        $subscription = [];
        if (filled(data_get($session, 'subscription'))) {
            $subscription = $this->normalizeStripeObject($stripe->retrieveSubscription((string) data_get($session, 'subscription')));
        }

        if ($subscription === []) {
            $subscription = [
                'id' => data_get($session, 'subscription'),
                'customer' => data_get($session, 'customer'),
                'status' => data_get($session, 'subscription_status', 'active'),
            ];
        }

        $this->applySubscription($salon, $subscription, data_get($session, 'metadata.plan_key'));
    }

    private function handleSubscriptionUpdated(array $event): void
    {
        $subscription = data_get($event, 'data.object', []);
        $salon = $this->findSalon(
            data_get($subscription, 'id'),
            data_get($subscription, 'metadata.salon_id'),
            data_get($subscription, 'customer'),
        );

        if (! $salon) {
            Log::warning('Stripe subscription update for unknown salon.', ['subscription_id' => data_get($subscription, 'id')]);

            return;
        }

        $this->applySubscription($salon, $subscription, data_get($subscription, 'metadata.plan_key'));
    }

    private function handleSubscriptionDeleted(array $event): void
    {
        $subscription = data_get($event, 'data.object', []);
        $salon = $this->findSalon(data_get($subscription, 'id'), data_get($subscription, 'metadata.salon_id'), data_get($subscription, 'customer'));

        if (! $salon) {
            return;
        }

        $salon->forceFill([
            'plan' => 'free',
            'plan_started_at' => now(),
            'stripe_subscription_id' => null,
            'stripe_price_id' => null,
            'subscription_status' => 'canceled',
            'subscription_current_period_end' => $this->periodEnd(data_get($subscription, 'current_period_end')),
        ])->save();
    }

    private function handleInvoicePaymentSucceeded(array $event): void
    {
        $invoice = data_get($event, 'data.object', []);
        $salon = $this->findSalon(data_get($invoice, 'subscription'), null, data_get($invoice, 'customer'));

        if (! $salon || ! $salon->stripe_price_id) {
            return;
        }

        $planKey = StripePlans::planKeyForPriceId($salon->stripe_price_id);
        if (! $planKey) {
            return;
        }

        $salon->forceFill([
            'plan' => $planKey,
            'plan_started_at' => $salon->plan === $planKey ? $salon->plan_started_at : now(),
            'subscription_status' => 'active',
        ])->save();
    }

    private function handleInvoicePaymentFailed(array $event): void
    {
        $invoice = data_get($event, 'data.object', []);
        $salon = $this->findSalon(data_get($invoice, 'subscription'), null, data_get($invoice, 'customer'));

        if (! $salon) {
            return;
        }

        $salon->forceFill(['subscription_status' => 'payment_failed'])->save();
    }

    private function applySubscription(Salon $salon, array $subscription, ?string $metadataPlanKey = null): void
    {
        $priceId = $this->subscriptionPriceId($subscription);
        if (! $priceId) {
            Log::warning('Stripe subscription missing price.', ['subscription_id' => data_get($subscription, 'id')]);

            return;
        }

        $planKey = StripePlans::planKeyForPriceId($priceId);
        if (! $planKey) {
            Log::warning('Stripe subscription has unknown price.', [
                'subscription_id' => data_get($subscription, 'id'),
                'price_id' => $priceId,
            ]);

            return;
        }

        $status = (string) data_get($subscription, 'status', 'incomplete');
        $updates = [
            'stripe_customer_id' => data_get($subscription, 'customer', $salon->stripe_customer_id),
            'stripe_subscription_id' => data_get($subscription, 'id', $salon->stripe_subscription_id),
            'stripe_price_id' => $priceId,
            'subscription_status' => $status,
            'subscription_current_period_end' => $this->periodEnd(data_get($subscription, 'current_period_end')),
        ];

        if (in_array($status, ['active', 'trialing'], true)) {
            $updates['plan'] = $planKey;
            $updates['plan_started_at'] = $salon->plan === $planKey ? $salon->plan_started_at : now();
        } elseif (in_array($status, ['canceled'], true)) {
            $updates['plan'] = 'free';
            $updates['plan_started_at'] = now();
        } elseif ($salon->plan === 'free' && $metadataPlanKey !== $planKey) {
            Log::info('Stripe subscription not active; plan remains free.', [
                'subscription_id' => data_get($subscription, 'id'),
                'status' => $status,
            ]);
        }

        $salon->forceFill($updates)->save();
    }

    private function findSalon(?string $subscriptionId, int|string|null $salonId, ?string $customerId): ?Salon
    {
        if (! $subscriptionId && ! $salonId && ! $customerId) {
            return null;
        }

        return Salon::query()
            ->when($subscriptionId, fn ($query) => $query->orWhere('stripe_subscription_id', $subscriptionId))
            ->when($salonId, fn ($query) => $query->orWhere('id', $salonId))
            ->when($customerId, fn ($query) => $query->orWhere('stripe_customer_id', $customerId))
            ->first();
    }

    private function subscriptionPriceId(array $subscription): ?string
    {
        $item = data_get($subscription, 'items.data.0');

        return data_get($item, 'price.id') ?: data_get($item, 'plan.id');
    }

    private function periodEnd(mixed $timestamp): ?Carbon
    {
        return is_numeric($timestamp) ? Carbon::createFromTimestamp((int) $timestamp) : null;
    }

    private function normalizeStripeObject(object|array $object): array
    {
        return json_decode(json_encode($object), true) ?: [];
    }
}
