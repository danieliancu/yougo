<?php

namespace App\Services\Business;

use App\Support\BusinessTaxonomy;
use Illuminate\Support\Str;

/**
 * Matches free-text industry signals (e.g. the AI-extracted `business.business_type`
 * fact from the onboarding website analyzer) against the canonical taxonomy's curated
 * `aliases` and industry labels/slugs.
 *
 * Deliberately exact-match only, never substring/fuzzy — a loose match risks silently
 * classifying a business into the wrong industry (wrong required fields, wrong safety
 * instructions). An unmatched text is reported as uncertain and routed to the onboarding
 * review flow instead of guessing (see OnboardingDraftConfirmationService).
 */
class IndustryMatcher
{
    /**
     * @return array{matched: bool, business_type: ?string, industry: ?string}
     */
    public function match(?string $freeText): array
    {
        $none = ['matched' => false, 'business_type' => null, 'industry' => null];

        if (! $freeText || trim($freeText) === '') {
            return $none;
        }

        $normalized = self::normalize($freeText);

        if ($normalized === '') {
            return $none;
        }

        foreach (BusinessTaxonomy::all() as $businessType) {
            if (self::normalize($businessType['label']) === $normalized || self::normalize($businessType['slug']) === $normalized) {
                return ['matched' => true, 'business_type' => $businessType['slug'], 'industry' => null];
            }

            foreach ($businessType['aliases'] ?? [] as $alias) {
                if (self::normalize($alias) === $normalized) {
                    return ['matched' => true, 'business_type' => $businessType['slug'], 'industry' => null];
                }
            }

            foreach ($businessType['industries'] as $industry) {
                if (self::normalize($industry['label']) === $normalized || self::normalize($industry['slug']) === $normalized) {
                    return ['matched' => true, 'business_type' => $businessType['slug'], 'industry' => $industry['slug']];
                }
            }
        }

        return $none;
    }

    public static function normalize(string $value): string
    {
        return (string) Str::of($value)->lower()->ascii()->squish();
    }
}
