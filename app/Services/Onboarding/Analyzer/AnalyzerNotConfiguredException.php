<?php

namespace App\Services\Onboarding\Analyzer;

/**
 * Thrown by the production placeholder analyzer. This is a permanent failure —
 * the job must not retry it, since retrying can never succeed.
 */
class AnalyzerNotConfiguredException extends AnalyzerFailedException
{
    public function failureCode(): string
    {
        return 'analyzer_not_configured';
    }
}
