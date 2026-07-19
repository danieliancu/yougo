<?php

namespace App\Services\Onboarding\Analyzer;

class SourceBlockedException extends AnalyzerFailedException
{
    public function failureCode(): string
    {
        return 'source_blocked';
    }
}
