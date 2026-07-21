<?php

namespace App\Services\Onboarding;

use App\DataTransferObjects\Onboarding\ImportedFact;

/**
 * Reconciles two candidate ImportedFacts for the same field. Extracted out of
 * OnboardingEntityDeduplicator (Task 1) so the same merge rule can be reused by
 * GeminiWebsiteOnboardingAnalyzer (Task 2) to reconcile business/contact scalar
 * candidates gathered across multiple crawled pages — one rule, one place, instead of
 * two copies drifting apart.
 */
class ImportedFactMerger
{
    /**
     * Identical values keep the first mention; conflicting values are kept as a
     * conflict on the field and force requires_confirmation=true rather than silently
     * picking one.
     */
    public function merge(?ImportedFact $a, ?ImportedFact $b): ?ImportedFact
    {
        if ($a === null) {
            return $b;
        }

        if ($b === null) {
            return $a;
        }

        if ($this->valuesEqual($a->value, $b->value)) {
            return $a;
        }

        return $a->withConflicts([...$a->conflicts, $b], true);
    }

    public function valuesEqual(mixed $a, mixed $b): bool
    {
        if (is_string($a) && is_string($b)) {
            return mb_strtolower(trim($a)) === mb_strtolower(trim($b));
        }

        return $a == $b; // phpcs:ignore
    }
}
