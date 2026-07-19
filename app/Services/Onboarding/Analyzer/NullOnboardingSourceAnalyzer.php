<?php

namespace App\Services\Onboarding\Analyzer;

use App\DataTransferObjects\Onboarding\OnboardingAnalysisResult;
use App\Models\OnboardingDraft;

/**
 * Production placeholder — no real crawler/AI provider is wired in Task 1. Always
 * fails with a permanent, safe-to-display error, and never performs any I/O.
 */
class NullOnboardingSourceAnalyzer implements OnboardingSourceAnalyzer
{
    public function analyze(OnboardingDraft $draft): OnboardingAnalysisResult
    {
        throw new AnalyzerNotConfiguredException('Import from this source is not yet available.');
    }
}
