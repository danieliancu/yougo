<?php

namespace App\Services\Onboarding\Crawler;

final readonly class CrawlResult
{
    /**
     * @param  list<CrawledPage>  $pages
     * @param  list<string>  $warnings
     * @param  list<string>  $ignoredUrls
     */
    public function __construct(
        public array $pages,
        public array $warnings,
        public string $stopReason,
        public array $ignoredUrls,
    ) {}
}
