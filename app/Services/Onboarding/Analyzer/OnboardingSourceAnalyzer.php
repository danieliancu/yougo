<?php

namespace App\Services\Onboarding\Analyzer;

use App\DataTransferObjects\Onboarding\OnboardingAnalysisResult;
use App\Models\OnboardingDraft;

/**
 * Analyzes an onboarding source (today: a website URL) and returns raw + best-effort
 * normalized data. Implementations MUST NOT include cookies, authorization headers, or
 * other secrets in the raw payload. Implementations MUST NOT write to the database —
 * they receive a read-only draft snapshot and return a result; persistence is entirely
 * the job's responsibility.
 */
interface OnboardingSourceAnalyzer
{
    /**
     * @throws AnalyzerFailedException
     */
    public function analyze(OnboardingDraft $draft): OnboardingAnalysisResult;
}
