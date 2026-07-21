<?php

namespace App\Services\Onboarding\Analyzer;

/**
 * Non-`url` source types, or a URL that's clearly a social/Google-Business page —
 * detected before crawling starts. Permanent (not retryable) — never rethrown by the
 * job for automatic retry.
 */
class SourceUnsupportedException extends AnalyzerFailedException
{
    public function failureCode(): string
    {
        return 'source_unsupported';
    }
}
