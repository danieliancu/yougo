<?php

namespace App\DataTransferObjects\Onboarding;

/**
 * What an OnboardingSourceAnalyzer returns. `normalized` is an untyped array shaped
 * per the current schema — it is NOT yet validated. The job (Phase B) is the single,
 * central place that turns it into a NormalizedExtractionResult via ::fromArray().
 */
final readonly class OnboardingAnalysisResult
{
    /**
     * @param  array<string, mixed>  $raw  provider's raw payload; analyzer must have already scrubbed secrets/auth headers/cookies
     * @param  array<string, mixed>  $normalized  untyped, not yet validated
     * @param  array<string, mixed>  $providerMetadata  provider name, model/version, duration_ms, request_id — no secrets
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $raw,
        public array $normalized,
        public string $schemaVersion,
        public array $providerMetadata = [],
        public array $warnings = [],
    ) {}
}
