<?php

namespace App\Services\Onboarding\Analyzer;

class SourceUnreachableException extends AnalyzerFailedException
{
    public function failureCode(): string
    {
        return 'source_unreachable';
    }
}
