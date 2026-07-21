<?php

namespace App\Services\Onboarding\Analyzer;

use RuntimeException;

/**
 * Base exception for analyzer failures. The message on this exception (and subclasses)
 * is never persisted or shown to the user directly — the job maps it to a safe,
 * whitelisted failure_code/failure_message before storing anything on the draft.
 */
class AnalyzerFailedException extends RuntimeException
{
    public function failureCode(): string
    {
        return 'analyzer_failed';
    }
}
