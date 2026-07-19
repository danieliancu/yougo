<?php

namespace App\Services\Onboarding\Fetcher;

use RuntimeException;

/**
 * Test double recording every call (method, URL, and — crucially — the exact options
 * passed, including `pinned_ip`) so a fetcher test can assert DNS pinning was actually
 * requested for every hop, including redirects. This is what Http::fake() cannot prove
 * (it never reaches the curl-option layer).
 */
class SpyOnboardingHttpTransport implements OnboardingHttpTransport
{
    /**
     * @var array<string, list<TransportResponse>>
     */
    private array $queuedResponses = [];

    /**
     * @var list<array{method: string, url: string, options: array}>
     */
    public array $calls = [];

    public function willRespond(string $url, TransportResponse $response): static
    {
        $this->queuedResponses[$url][] = $response;

        return $this;
    }

    public function send(string $method, string $url, array $options = []): TransportResponse
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'options' => $options];

        if (empty($this->queuedResponses[$url])) {
            throw new RuntimeException("SpyOnboardingHttpTransport has no queued response for [{$url}].");
        }

        return array_shift($this->queuedResponses[$url]);
    }

    /**
     * @return list<array{method: string, url: string, options: array}>
     */
    public function callsTo(string $url): array
    {
        return array_values(array_filter($this->calls, fn (array $call) => $call['url'] === $url));
    }
}
