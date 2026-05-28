<?php

namespace App\Support;

use RuntimeException;

class StripePlans
{
    /** @return array<int, string> */
    public static function paidPlanKeys(): array
    {
        return array_keys(config('stripe.prices', []));
    }

    public static function priceIdForPlan(string $planKey): ?string
    {
        if (! self::isPaidPlan($planKey)) {
            return null;
        }

        $priceId = config("stripe.prices.{$planKey}");

        return filled($priceId) ? (string) $priceId : null;
    }

    public static function planKeyForPriceId(string $priceId): ?string
    {
        foreach (config('stripe.prices', []) as $planKey => $configuredPriceId) {
            if (filled($configuredPriceId) && hash_equals((string) $configuredPriceId, $priceId)) {
                return $planKey;
            }
        }

        return null;
    }

    public static function isPaidPlan(string $planKey): bool
    {
        return $planKey !== 'free'
            && array_key_exists($planKey, config('yougo_plans', []))
            && array_key_exists($planKey, config('stripe.prices', []));
    }

    /** @return array<int, string> */
    public static function configuredPriceErrors(): array
    {
        $errors = [];

        if (! filled(config('stripe.key'))) {
            $errors[] = 'STRIPE_KEY is not configured.';
        }

        if (! filled(config('stripe.secret'))) {
            $errors[] = 'STRIPE_SECRET is not configured.';
        }

        if (! filled(config('stripe.webhook_secret'))) {
            $errors[] = 'STRIPE_WEBHOOK_SECRET is not configured.';
        }

        if (array_key_exists('free', config('stripe.prices', []))) {
            $errors[] = 'The internal free plan must not have a Stripe price mapping.';
        }

        foreach (self::paidPlanKeys() as $planKey) {
            if (! array_key_exists($planKey, config('yougo_plans', []))) {
                $errors[] = "Stripe price mapping references unknown plan [{$planKey}].";
            }

            if (! filled(config("stripe.prices.{$planKey}"))) {
                $errors[] = "Missing Stripe price ID for paid plan [{$planKey}].";
            }
        }

        return $errors;
    }

    public static function validateConfiguredPrices(): void
    {
        $errors = self::configuredPriceErrors();

        if ($errors !== []) {
            throw new RuntimeException(implode(' ', $errors));
        }
    }
}
