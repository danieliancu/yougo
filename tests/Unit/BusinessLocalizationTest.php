<?php

namespace Tests\Unit;

use App\Models\Salon;
use App\Support\BusinessLocalization;
use Tests\TestCase;

class BusinessLocalizationTest extends TestCase
{
    public function test_normalizes_uk_to_gb(): void
    {
        $this->assertSame('GB', BusinessLocalization::normalizeCountry('UK'));
    }

    public function test_ro_defaults(): void
    {
        $this->assertSame('RON', BusinessLocalization::currencyFor('RO'));
        $this->assertSame('+40', BusinessLocalization::phonePrefixFor('RO'));
        $this->assertSame('Europe/Bucharest', BusinessLocalization::timezoneFor('RO'));
        $this->assertSame('dd.mm.yyyy', BusinessLocalization::dateFormatFor('RO'));
    }

    public function test_gb_defaults(): void
    {
        $this->assertSame('GBP', BusinessLocalization::currencyFor('GB'));
        $this->assertSame('+44', BusinessLocalization::phonePrefixFor('GB'));
        $this->assertSame('Europe/London', BusinessLocalization::timezoneFor('GB'));
        $this->assertSame('dd/mm/yyyy', BusinessLocalization::dateFormatFor('GB'));
    }

    public function test_unknown_country_falls_back_safely(): void
    {
        $this->assertSame('RO', BusinessLocalization::normalizeCountry('ZZ'));
        $this->assertSame('RON', BusinessLocalization::currencyFor('ZZ'));
    }

    public function test_european_country_catalog_includes_uk_and_common_markets(): void
    {
        $countries = BusinessLocalization::countries();

        foreach (['RO', 'GB', 'FR', 'DE', 'IT', 'ES', 'NL', 'CH', 'NO', 'UA', 'MD'] as $country) {
            $this->assertArrayHasKey($country, $countries);
        }

        $this->assertGreaterThanOrEqual(40, count($countries));
    }

    public function test_formats_service_price_with_business_currency(): void
    {
        $salon = new Salon(['country' => 'GB', 'currency' => 'GBP']);

        $this->assertSame('£120', BusinessLocalization::formatServicePrice('120', $salon));
        $this->assertSame('$120', BusinessLocalization::formatServicePrice('120', $salon, 'USD'));
    }
}
