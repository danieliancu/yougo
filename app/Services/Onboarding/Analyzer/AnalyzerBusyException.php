<?php

namespace App\Services\Onboarding\Analyzer;

class AnalyzerBusyException extends AnalyzerFailedException
{
    public function failureCode(): string
    {
        return 'analyzer_busy';
    }
}
