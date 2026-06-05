<?php

namespace App\Support;

use App\Models\Salon;
use Illuminate\Support\Carbon;

class BusinessLocalization
{
    /** @return array<string, array<string, mixed>> */
    public static function countries(): array
    {
        return config('yougo_locales.countries', []);
    }

    /** @return array<int, array<string, mixed>> */
    public static function countryOptions(string $locale): array
    {
        $labelKey = $locale === 'en' ? 'label_en' : 'label_ro';

        return collect(self::countries())
            ->map(fn (array $country) => [
                'code' => $country['code'],
                'label' => $country[$labelKey] ?? $country['label_en'] ?? $country['code'],
                'currency' => $country['currency'],
                'phone_prefix' => $country['phone_prefix'],
                'default_timezone' => $country['default_timezone'],
                'default_language' => $country['default_language'],
                'date_formats' => $country['date_formats'],
                'default_date_format' => $country['default_date_format'],
                'time_format' => $country['time_format'],
            ])
            ->values()
            ->all();
    }

    public static function normalizeCountry(?string $country): string
    {
        $country = strtoupper(trim((string) $country));

        if ($country === '') {
            return self::fallbackCountry();
        }

        $aliases = config('yougo_locales.country_aliases', []);
        $country = $aliases[$country] ?? $country;

        return array_key_exists($country, self::countries()) ? $country : self::fallbackCountry();
    }

    /** @return array<string, mixed> */
    public static function countryConfig(?string $country): array
    {
        $normalized = self::normalizeCountry($country);

        return self::countries()[$normalized] ?? self::countries()[self::fallbackCountry()];
    }

    public static function currencyFor(?string $country): string
    {
        return self::countryConfig($country)['currency'];
    }

    public static function phonePrefixFor(?string $country): string
    {
        return self::countryConfig($country)['phone_prefix'];
    }

    public static function timezoneFor(?string $country): string
    {
        return self::countryConfig($country)['default_timezone'];
    }

    public static function dateFormatFor(?string $country): string
    {
        return self::countryConfig($country)['default_date_format'];
    }

    /** @return array<int, string> */
    public static function dateFormatOptions(?string $country): array
    {
        return self::countryConfig($country)['date_formats'];
    }

    public static function defaultLanguageFor(?string $country): string
    {
        return self::countryConfig($country)['default_language'];
    }

    /** @return array<int, string> */
    public static function serviceCurrencyCodes(?string $country = null): array
    {
        return collect([
            self::currencyFor($country),
            'EUR',
            'GBP',
            'USD',
        ])
            ->map(fn (string $currency) => strtoupper($currency))
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<int, array<string, string>> */
    public static function serviceCurrencyOptions(?string $country = null): array
    {
        return collect(self::serviceCurrencyCodes($country))
            ->map(fn (string $currency) => [
                'code' => $currency,
                'label' => match ($currency) {
                    'GBP' => 'GBP (£)',
                    'USD' => 'USD ($)',
                    'EUR' => 'EUR (€)',
                    default => $currency,
                },
            ])
            ->all();
    }

    public static function normalizeServiceCurrency(?string $currency, ?string $country = null): string
    {
        $currency = strtoupper(trim((string) $currency));

        return in_array($currency, self::serviceCurrencyCodes($country), true)
            ? $currency
            : self::currencyFor($country);
    }

    public static function isSupportedCountry(?string $country): bool
    {
        $country = strtoupper(trim((string) $country));
        $aliases = config('yougo_locales.country_aliases', []);
        $country = $aliases[$country] ?? $country;

        return array_key_exists($country, self::countries());
    }

    /** @return array<int, string> */
    public static function timezoneOptions(): array
    {
        return config('yougo_locales.timezones', [
            'Europe/Bucharest',
            'Europe/London',
        ]);
    }

    /** @return array<int, string> */
    public static function allDateFormats(): array
    {
        return collect(self::countries())
            ->flatMap(fn (array $country) => $country['date_formats'] ?? [])
            ->unique()
            ->values()
            ->all();
    }

    public static function normalizeDateFormat(?string $dateFormat, ?string $country = null): string
    {
        $normalized = strtolower(trim((string) $dateFormat));
        $normalized = match ($normalized) {
            'dd.mm.yyyy', 'dd.mm.yyyy.' => 'dd.mm.yyyy',
            'dd/mm/yyyy', 'dd-mm-yyyy' => 'dd/mm/yyyy',
            'yyyy-mm-dd', 'yyyy/mm/dd' => 'yyyy-mm-dd',
            'dd month yyyy', 'd month yyyy', 'dd mmmm yyyy', 'd mmmm yyyy' => 'dd month yyyy',
            default => $normalized,
        };

        return in_array($normalized, self::allDateFormats(), true)
            ? $normalized
            : self::dateFormatFor($country);
    }

    public static function normalizeTimezone(?string $timezone, ?string $country = null): string
    {
        $timezone = trim((string) $timezone);

        return in_array($timezone, self::timezoneOptions(), true)
            ? $timezone
            : self::timezoneFor($country);
    }

    public static function formatDate(Carbon|string|null $date, Salon $salon): string
    {
        if (! $date) {
            return '';
        }

        $carbon = $date instanceof Carbon ? $date : Carbon::parse($date);
        $format = self::normalizeDateFormat($salon->date_format, $salon->country);

        return match ($format) {
            'dd.mm.yyyy' => $carbon->format('d.m.Y'),
            'dd/mm/yyyy' => $carbon->format('d/m/Y'),
            'yyyy-mm-dd' => $carbon->format('Y-m-d'),
            'dd month yyyy' => $carbon->locale(($salon->display_language ?? 'ro') === 'en' ? 'en' : 'ro')->translatedFormat('d F Y'),
            default => $carbon->format('d.m.Y'),
        };
    }

    public static function formatServicePrice(string|int|float|null $price, Salon $salon, ?string $currency = null): ?string
    {
        if ($price === null || $price === '') {
            return null;
        }

        $price = trim((string) $price);

        if (preg_match('/\b(RON|GBP|EUR|USD)\b|£|€|\$/iu', $price)) {
            return $price;
        }

        $currency = self::normalizeServiceCurrency($currency ?: $salon->currency, $salon->country);

        return match ($currency) {
            'GBP' => "£{$price}",
            'USD' => "\${$price}",
            'EUR' => "{$price} EUR",
            'RON' => "{$price} RON",
            default => "{$price} {$currency}",
        };
    }

    private static function fallbackCountry(): string
    {
        return config('yougo_locales.fallback_country', 'RO');
    }
}
