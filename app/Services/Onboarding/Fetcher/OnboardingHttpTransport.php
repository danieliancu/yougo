<?php

namespace App\Services\Onboarding\Fetcher;

/**
 * The only seam that touches the real HTTP client/curl. HttpWebsiteSourceFetcher
 * depends on this interface, never on Http::withOptions()/curl directly — this is
 * what makes DNS pinning verifiable in fast unit tests (a spy implementation records
 * the exact options passed, including `pinned_ip`) without needing a real socket, and
 * what keeps the one class that actually performs network I/O small and swappable.
 */
interface OnboardingHttpTransport
{
    /**
     * @param  array{pinned_ip?: string, headers?: array<string,string>, connect_timeout?: int, timeout?: int, verify?: string}  $options
     */
    public function send(string $method, string $url, array $options = []): TransportResponse;
}
