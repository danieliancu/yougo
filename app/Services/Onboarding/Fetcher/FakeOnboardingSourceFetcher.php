<?php

namespace App\Services\Onboarding\Fetcher;

/**
 * Deterministic, no-I/O OnboardingSourceFetcher used by crawler/analyzer tests that
 * don't need to exercise the real SSRF/pinning stack (that's HttpWebsiteSourceFetcher's
 * own test suite's job) — just scripted responses per URL.
 */
class FakeOnboardingSourceFetcher implements OnboardingSourceFetcher
{
    /**
     * @var array<string, FetchedDocument>
     */
    private array $documents = [];

    /**
     * @var array<string, FetchException>
     */
    private array $failures = [];

    /**
     * @var list<string>
     */
    public array $requestedUrls = [];

    public function willReturnHtml(string $url, string $html): static
    {
        $this->documents[$url] = new FetchedDocument($url, $url, 200, 'text/html', $html, strlen($html));

        return $this;
    }

    public function willReturnDocument(string $url, FetchedDocument $document): static
    {
        $this->documents[$url] = $document;

        return $this;
    }

    public function willFail(string $url, FetchException $exception): static
    {
        $this->failures[$url] = $exception;

        return $this;
    }

    public function fetch(string $url, array $allowedContentTypes, int $maxBytes): FetchedDocument
    {
        $this->requestedUrls[] = $url;

        if (isset($this->failures[$url])) {
            throw $this->failures[$url];
        }

        if (isset($this->documents[$url])) {
            return $this->documents[$url];
        }

        throw new FetchException("No fake document registered for [{$url}].", 'source_unreachable');
    }
}
