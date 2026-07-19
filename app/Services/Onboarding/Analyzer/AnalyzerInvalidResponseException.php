<?php

namespace App\Services\Onboarding\Analyzer;

/**
 * The AI's output was still not valid/parseable after the one bounded repair retry.
 */
class AnalyzerInvalidResponseException extends AnalyzerFailedException
{
    public function failureCode(): string
    {
        return 'analyzer_invalid_response';
    }
}
