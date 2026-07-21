<?php

namespace App\Services\Onboarding\Analyzer;

class SourceRequiresAuthenticationException extends AnalyzerFailedException
{
    public function failureCode(): string
    {
        return 'source_requires_authentication';
    }
}
