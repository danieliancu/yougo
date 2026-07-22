<?php

namespace App\Services\Business;

use App\Models\OnboardingDraft;
use App\Models\Salon;
use App\Support\BusinessTaxonomy;

/**
 * Computes and applies Industry Defaults (Task 4 §5).
 *
 * Three distinct writes, kept strictly separate so recommended/confirmed/customized never
 * collapse into one ambiguous state:
 *  - recommendationFor(): pure computation, no side effects.
 *  - applyRecommendation(): writes only the `recommended_*` fields on the salon (never the
 *    active capabilities), guarded by draft/revision staleness when a draft is given.
 *  - confirm(): the only method that writes the active `primary_capability`/
 *    `enabled_capabilities` (via Salon::setCapabilities()), always as an explicit user
 *    action — never called automatically from an import/analysis pipeline.
 */
class IndustryDefaultsService
{
    /**
     * @return array{primary: string, secondary: list<string>}
     */
    public function recommendationFor(string $businessTypeSlug, ?string $industrySlug = null): array
    {
        $businessType = BusinessTaxonomy::findBusinessType($businessTypeSlug);

        if (! $businessType) {
            return ['primary' => Salon::CAPABILITY_APPOINTMENT, 'secondary' => []];
        }

        $override = $industrySlug
            ? (BusinessTaxonomy::findIndustry($businessTypeSlug, $industrySlug)['capabilities_override'] ?? null)
            : null;

        if (is_array($override) && isset($override['primary'])) {
            return [
                'primary' => $override['primary'],
                'secondary' => array_values($override['secondary'] ?? []),
            ];
        }

        return [
            'primary' => $businessType['primary_capability'] ?? Salon::CAPABILITY_APPOINTMENT,
            'secondary' => array_values($businessType['secondary_capabilities'] ?? []),
        ];
    }

    /**
     * Writes only `recommended_primary_capability`/`recommended_secondary_capabilities`
     * (+ the draft/revision it came from). Never touches the active capability columns —
     * those only change via confirm(). Idempotent: calling this repeatedly with the same
     * inputs is a no-op after the first write for that draft/revision.
     */
    public function applyRecommendation(Salon $salon, string $businessTypeSlug, ?string $industrySlug = null, ?OnboardingDraft $sourceDraft = null): bool
    {
        if ($sourceDraft && ! $this->isDraftFreshEnough($salon, $sourceDraft)) {
            return false;
        }

        $recommendation = $this->recommendationFor($businessTypeSlug, $industrySlug);

        $salon->forceFill([
            'recommended_primary_capability' => $recommendation['primary'],
            'recommended_secondary_capabilities' => $recommendation['secondary'],
            'recommended_source_draft_id' => $sourceDraft?->id,
            'recommended_source_revision' => $sourceDraft?->revision,
        ])->save();

        return true;
    }

    /**
     * The single point that activates a capability configuration for a salon. `$customized`
     * distinguishes "user accepted the recommendation as-is" (confirmed) from "user changed
     * it" (custom) — both lock the configuration against silent future overwrites.
     *
     * @param  list<string>  $secondary
     */
    public function confirm(Salon $salon, string $primary, array $secondary, bool $customized): void
    {
        $enabled = array_values(array_unique([$primary, ...$secondary]));

        $salon->setCapabilities(
            $primary,
            $enabled,
            $customized ? Salon::CAPABILITIES_SOURCE_CUSTOM : Salon::CAPABILITIES_SOURCE_CONFIRMED,
        );
    }

    /**
     * A recommendation from an older/superseded draft (or a stale retry of the same draft)
     * must never overwrite one already produced by a newer draft. A later draft (higher id)
     * always wins; within the same draft, only a higher revision counts as newer.
     */
    private function isDraftFreshEnough(Salon $salon, OnboardingDraft $sourceDraft): bool
    {
        if (! $salon->recommended_source_draft_id) {
            return true;
        }

        if ($sourceDraft->id !== $salon->recommended_source_draft_id) {
            return $sourceDraft->id > $salon->recommended_source_draft_id;
        }

        return $sourceDraft->revision >= ($salon->recommended_source_revision ?? 0);
    }
}
