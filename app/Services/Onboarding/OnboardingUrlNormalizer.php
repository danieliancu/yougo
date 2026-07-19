<?php

namespace App\Services\Onboarding;

/**
 * Canonicalizes a URL so "the same source" always maps to the same string, both
 * before SSRF validation and before computing the idempotency key. Deliberately does
 * NOT reject anything — an unparseable URL is passed through unchanged and left for
 * OnboardingUrlValidator to reject with a proper reason code, so there's exactly one
 * place that decides a URL is invalid.
 *
 * `www` vs non-`www` hosts are deliberately NOT merged: determining true equivalence
 * would require following an actual HTTP redirect, which is I/O and does not belong in
 * synchronous normalization. Submitting both variants can produce two drafts for what
 * turns out to be the same site — a documented, low-impact simplification for Task 1.
 */
class OnboardingUrlNormalizer
{
    public function normalize(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);

        if (function_exists('idn_to_ascii')) {
            $ascii = @idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            if (is_string($ascii) && $ascii !== '') {
                $host = $ascii;
            }
        }

        $port = $parts['port'] ?? null;

        if ($port !== null) {
            $isDefaultPort = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);

            if ($isDefaultPort) {
                $port = null;
            }
        }

        $path = $parts['path'] ?? '';

        if ($path === '/') {
            $path = '';
        }

        $query = '';

        if (isset($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $queryParams);
            ksort($queryParams);
            $query = http_build_query($queryParams);
        }

        $normalized = "{$scheme}://{$host}";

        if ($port !== null) {
            $normalized .= ":{$port}";
        }

        $normalized .= $path;

        if ($query !== '') {
            $normalized .= "?{$query}";
        }

        // Fragment and any user:pass component are intentionally never round-tripped —
        // the fragment carries no server-relevant identity, and credentials-in-URL are
        // rejected outright by OnboardingUrlValidator, never persisted or logged.
        return $normalized;
    }
}
