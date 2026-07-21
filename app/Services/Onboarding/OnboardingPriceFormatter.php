<?php

namespace App\Services\Onboarding;

/**
 * The only place a price value is converted into the plain string Service.price
 * (app/Models/Service.php) column expects. Task 2's normalized extraction schema
 * represents "de la 100 lei" / price ranges as a structured value
 * ({"type":"fixed"|"from"|"range", "amount"|"min"/"max", "currency"}) so they're never
 * collapsed into a single fixed number too early — this formatter is where that
 * structure finally becomes display text, and nowhere else. Never returns a serialized
 * array ("Array") for malformed input.
 */
class OnboardingPriceFormatter
{
    public function toDisplayString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_string($value) || is_numeric($value)) {
            return (string) $value;
        }

        if (! is_array($value)) {
            return '';
        }

        return match ($value['type'] ?? null) {
            'fixed' => $this->formatAmount($value['amount'] ?? null),
            'from' => $this->formatAmount($value['amount'] ?? null, prefix: 'de la '),
            'range' => $this->formatRange($value['min'] ?? null, $value['max'] ?? null),
            default => '',
        };
    }

    private function formatAmount(mixed $amount, string $prefix = ''): string
    {
        if (! is_numeric($amount)) {
            return '';
        }

        return $prefix.$this->trimNumber($amount);
    }

    private function formatRange(mixed $min, mixed $max): string
    {
        if (! is_numeric($min) || ! is_numeric($max)) {
            return '';
        }

        return $this->trimNumber($min).'-'.$this->trimNumber($max);
    }

    private function trimNumber(mixed $value): string
    {
        $float = (float) $value;

        return rtrim(rtrim(sprintf('%.2f', $float), '0'), '.') ?: '0';
    }
}
