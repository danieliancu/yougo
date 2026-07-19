<?php

namespace Tests\Unit\Onboarding;

use App\Services\Onboarding\OnboardingPriceFormatter;
use Tests\TestCase;

class OnboardingPriceFormatterTest extends TestCase
{
    public function test_fixed_price_formats_as_plain_number(): void
    {
        $formatter = new OnboardingPriceFormatter;

        $this->assertSame('100', $formatter->toDisplayString(['type' => 'fixed', 'amount' => 100, 'currency' => 'RON']));
    }

    public function test_from_price_formats_with_de_la_prefix(): void
    {
        $formatter = new OnboardingPriceFormatter;

        $this->assertSame('de la 100', $formatter->toDisplayString(['type' => 'from', 'amount' => 100, 'currency' => 'RON']));
    }

    public function test_range_price_formats_as_min_dash_max(): void
    {
        $formatter = new OnboardingPriceFormatter;

        $this->assertSame('100-200', $formatter->toDisplayString(['type' => 'range', 'min' => 100, 'max' => 200, 'currency' => 'RON']));
    }

    public function test_plain_scalar_passes_through(): void
    {
        $formatter = new OnboardingPriceFormatter;

        $this->assertSame('150', $formatter->toDisplayString('150'));
        $this->assertSame('150', $formatter->toDisplayString(150));
    }

    public function test_null_formats_to_empty_string(): void
    {
        $formatter = new OnboardingPriceFormatter;

        $this->assertSame('', $formatter->toDisplayString(null));
    }

    public function test_malformed_structured_value_formats_to_empty_string_not_array(): void
    {
        $formatter = new OnboardingPriceFormatter;

        $this->assertSame('', $formatter->toDisplayString(['type' => 'fixed']));
        $this->assertSame('', $formatter->toDisplayString(['type' => 'unknown', 'amount' => 100]));
        $this->assertSame('', $formatter->toDisplayString(['no_type_key' => true]));
    }

    public function test_decimal_amounts_are_preserved(): void
    {
        $formatter = new OnboardingPriceFormatter;

        $this->assertSame('99.5', $formatter->toDisplayString(['type' => 'fixed', 'amount' => 99.5]));
    }
}
