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
        // null | fake | gemini_website
        'driver' => env('ONBOARDING_ANALYZER_DRIVER', 'null'),
        'confidence_auto_accept_threshold' => 0.75,
        'gemini' => [
            // Reuses services.gemini.key/ca_bundle (config/services.php) — no separate API key.
            'model' => env('ONBOARDING_GEMINI_MODEL', env('GEMINI_MODEL', 'gemini-3-flash-preview')),
            // A large-but-legitimate completion (e.g. a dense price list) can take
            // 30-60s to generate; 30s was cutting off calls that were about to succeed.
            'timeout_seconds' => (int) env('ONBOARDING_GEMINI_TIMEOUT', 60),
            'max_repair_attempts' => 1,
            // Global AI cost/latency ceilings, independent of the crawl limits below —
            // 12 pages x a 30s Gemini call each would make onboarding slow and costly.
            'max_ai_calls' => (int) env('ONBOARDING_GEMINI_MAX_CALLS', 6),
            'max_input_characters_per_call' => (int) env('ONBOARDING_GEMINI_MAX_CHARS_PER_CALL', 12000),
            // A single page whose own text alone exceeds this is split into several
            // same-source-url chunks before batching — bounds a dense page's (e.g. a
            // full price list) expected output per call, independent of
            // max_input_characters_per_call, which governs combining separate pages.
            'max_input_characters_per_dense_chunk' => (int) env('ONBOARDING_GEMINI_MAX_CHARS_PER_DENSE_CHUNK', 1500),
            'max_output_tokens' => (int) env('ONBOARDING_GEMINI_MAX_OUTPUT_TOKENS', 8192),
            'total_analyzer_timeout_seconds' => (int) env('ONBOARDING_GEMINI_TOTAL_TIMEOUT', 90),
            // A busy/rate-limited batch (429/5xx, or a connection failure) is retried
            // this many times, with this delay between attempts, before its data is
            // given up on — bounded by total_analyzer_timeout_seconds either way.
            'max_busy_retries' => (int) env('ONBOARDING_GEMINI_MAX_BUSY_RETRIES', 2),
            'busy_retry_delay_seconds' => (int) env('ONBOARDING_GEMINI_BUSY_RETRY_DELAY', 3),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Website crawling
    |--------------------------------------------------------------------------
    |
    | Limits applied by OnboardingWebsiteCrawler / HttpWebsiteSourceFetcher when
    | analyzing a public business website (Task 2). Crawling stops controlledly once
    | any of these limits is reached — it never fails the import just because a limit
    | was hit; whatever was already extracted is kept.
    |
    */

    'crawl' => [
        'max_pages' => (int) env('ONBOARDING_CRAWL_MAX_PAGES', 12),
        'max_depth' => (int) env('ONBOARDING_CRAWL_MAX_DEPTH', 2),
        'max_redirects' => (int) env('ONBOARDING_CRAWL_MAX_REDIRECTS', 5),
        'connect_timeout_seconds' => (int) env('ONBOARDING_CRAWL_CONNECT_TIMEOUT', 5),
        'request_timeout_seconds' => (int) env('ONBOARDING_CRAWL_REQUEST_TIMEOUT', 12),
        'total_timeout_seconds' => (int) env('ONBOARDING_CRAWL_TOTAL_TIMEOUT', 45),
        'max_html_bytes_per_page' => (int) env('ONBOARDING_CRAWL_MAX_HTML_BYTES', 2 * 1024 * 1024),
        'max_sitemap_bytes' => (int) env('ONBOARDING_CRAWL_MAX_SITEMAP_BYTES', 512 * 1024),
        // A sitemap index lists other sitemap XML files rather than pages directly —
        // these bound how many child sitemaps get fetched and how many page URLs a
        // sitemap (index or plain) may contribute to the crawl frontier.
        'max_sitemap_files' => (int) env('ONBOARDING_CRAWL_MAX_SITEMAP_FILES', 5),
        'max_sitemap_urls' => (int) env('ONBOARDING_CRAWL_MAX_SITEMAP_URLS', 200),
        'max_total_download_bytes' => (int) env('ONBOARDING_CRAWL_MAX_TOTAL_BYTES', 8 * 1024 * 1024),
        'max_extracted_characters_per_page' => (int) env('ONBOARDING_CRAWL_MAX_CHARS_PER_PAGE', 30000),
        'max_total_extracted_characters' => (int) env('ONBOARDING_CRAWL_MAX_TOTAL_CHARS', 150000),
        'user_agent' => env('ONBOARDING_CRAWL_USER_AGENT', 'YouGoOnboardingBot/1.0 (+https://yougo.ro/onboarding)'),
        'max_concurrent_requests' => (int) env('ONBOARDING_CRAWL_CONCURRENCY', 2),
        // Testing-only escape hatch for the loopback smoke test (GuzzleOnboardingHttpTransportLoopbackTest):
        // lets OnboardingUrlValidator accept 127.0.0.1 so the real transport can be exercised against a
        // local PHP built-in server with zero public internet access. Must never be true outside `testing`.
        'allow_private_networks_for_testing' => (bool) env('ONBOARDING_ALLOW_PRIVATE_NETWORKS_FOR_TESTING', false),
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
