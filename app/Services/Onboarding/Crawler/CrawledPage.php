<?php

namespace App\Services\Onboarding\Crawler;

use App\Services\Onboarding\Extraction\ExtractedPage;

final readonly class CrawledPage
{
    public function __construct(
        public string $url,
        public int $depth,
        public string $discoveredVia,
        public ExtractedPage $extracted,
    ) {}
}
