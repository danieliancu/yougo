<?php

namespace App\Services\Onboarding\Analyzer;

/**
 * Pages were fetched but the extracted content is empty/trivial (e.g. a JS-only SPA
 * shell that exposes no useful HTML).
 */
class InsufficientPublicContentException extends AnalyzerFailedException
{
    public function failureCode(): string
    {
        return 'insufficient_public_content';
    }
}
