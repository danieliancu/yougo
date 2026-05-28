<?php

namespace App\Services\Billing;

use App\Models\Salon;
use App\Models\User;
use Stripe\StripeClient;

class StripeBillingGateway
{
    public function __construct(private readonly ?StripeClient $client = null)
    {
    }

    private function stripe(): StripeClient
    {
        return $this->client ?? new StripeClient((string) config('stripe.secret'));
    }

    /** @return array{id: string} */
    public function createCustomer(Salon $salon, User $user): array
    {
        $customer = $this->stripe()->customers->create([
            'name' => $salon->name,
            'email' => $user->email,
            'metadata' => [
                'salon_id' => (string) $salon->id,
                'user_id' => (string) $user->id,
            ],
        ]);

        return ['id' => $customer->id];
    }

    /** @param array<string, string> $metadata @return array{url: string} */
    public function createCheckoutSession(string $customerId, string $priceId, string $successUrl, string $cancelUrl, array $metadata): array
    {
        $session = $this->stripe()->checkout->sessions->create([
            'mode' => 'subscription',
            'customer' => $customerId,
            'line_items' => [[
                'price' => $priceId,
                'quantity' => 1,
            ]],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => $metadata,
            'subscription_data' => [
                'metadata' => $metadata,
            ],
        ]);

        return ['url' => $session->url];
    }

    /** @return array{url: string} */
    public function createPortalSession(string $customerId, string $returnUrl): array
    {
        $session = $this->stripe()->billingPortal->sessions->create([
            'customer' => $customerId,
            'return_url' => $returnUrl,
        ]);

        return ['url' => $session->url];
    }

    public function retrieveSubscription(string $subscriptionId): object
    {
        return $this->stripe()->subscriptions->retrieve($subscriptionId, []);
    }
}
