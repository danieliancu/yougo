<?php

namespace App\Services\Onboarding\Fetcher;

interface OnboardingSourceFetcher
{
    /**
     * @param  list<string>  $allowedContentTypes  e.g. ['text/html','application/xhtml+xml'] for pages,
     *                                             ['application/xml','text/xml'] for a sitemap fetch —
     *                                             content-type acceptance is per-call, not global.
     *
     * @throws FetchException
     */
    public function fetch(string $url, array $allowedContentTypes, int $maxBytes): FetchedDocument;
}
