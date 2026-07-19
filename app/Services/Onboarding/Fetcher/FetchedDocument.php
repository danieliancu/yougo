<?php

namespace App\Services\Onboarding\Fetcher;

final readonly class FetchedDocument
{
    public function __construct(
        public string $requestedUrl,
        public string $finalUrl,
        public int $statusCode,
        public string $contentType,
        public string $body,
        public int $sizeBytes,
    ) {}
}
