<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Source analyzer
    |--------------------------------------------------------------------------
    |
    | Which OnboardingSourceAnalyzer implementation to bind. "null" is the safe
    | production placeholder (throws AnalyzerNotConfiguredException, does not
    | fetch anything); "fake" is the deterministic, no-I/O implementation used
    | in tests.
    |
    */

    'analyzer' => [
        'driver' => env('ONBOARDING_ANALYZER_DRIVER', 'null'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Job behaviour
    |--------------------------------------------------------------------------
    */

    'job' => [
        'queue' => env('ONBOARDING_JOB_QUEUE', 'onboarding'),
        'tries' => (int) env('ONBOARDING_JOB_TRIES', 3),
        'timeout' => (int) env('ONBOARDING_JOB_TIMEOUT', 90),
        'backoff' => [30, 120, 300],
        // How long a Phase-A claim is considered "fresh" (still in flight) before
        // it's treated as a crashed job that can be reclaimed.
        'stale_after_seconds' => (int) env('ONBOARDING_JOB_STALE_AFTER_SECONDS', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Raw result storage and retention
    |--------------------------------------------------------------------------
    */

    'raw_result' => [
        'max_inline_bytes' => (int) env('ONBOARDING_RAW_RESULT_MAX_INLINE_BYTES', 65536),
        'disk' => env('ONBOARDING_RAW_RESULT_DISK', 'local'),
        'retention_days' => (int) env('ONBOARDING_RAW_RESULT_RETENTION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Source URL handling
    |--------------------------------------------------------------------------
    */

    'url' => [
        'max_length' => 2048,
    ],

];
