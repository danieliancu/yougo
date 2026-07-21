<?php

namespace App\Services\Onboarding\Fetcher;

use App\Services\Onboarding\OnboardingUrlNormalizer;
use App\Services\Onboarding\OnboardingUrlValidator;
use Throwable;

/**
 * SSRF-safe fetch of a single public URL. Re-validates and re-resolves at fetch time
 * (Task 1's submission-time OnboardingUrlValidator is not sufficient on its own — the
 * host could resolve to a private IP by the time this actually connects), and does the
 * same for every redirect hop, pinning each connection to the IP just validated via
 * OnboardingHttpTransport. Never forwards credentials; never follows an https->http
 * downgrade; caps redirects, response size, and content-type per call.
 *
 * Resolution happens exactly once per hop, via OnboardingUrlValidator::resolveValidatedIps()
 * — reused for both the safety check and the pinned IP, rather than resolving the host
 * a second time (which would double DNS traffic and reopen a small TOCTOU gap of its own).
 */
class HttpWebsiteSourceFetcher implements OnboardingSourceFetcher
{
    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    public function __construct(
        private readonly OnboardingHttpTransport $transport,
        private readonly OnboardingUrlValidator $urlValidator,
        private readonly OnboardingUrlNormalizer $urlNormalizer,
    ) {}

    public function fetch(string $url, array $allowedContentTypes, int $maxBytes): FetchedDocument
    {
        $requestedUrl = $this->urlNormalizer->normalize($url);
        $current = $requestedUrl;
        $maxRedirects = (int) config('onboarding.crawl.max_redirects', 5);
        $redirects = 0;

        while (true) {
            // Single resolution per hop, reused for both the safety check and the
            // pinned connection — resolving twice would double DNS traffic and reopen
            // a small TOCTOU gap between "validated" and "resolved again to pin".
            $ips = $this->urlValidator->resolveValidatedIps($current);

            $response = $this->send($current, $ips[0]);

            if (in_array($response->statusCode, self::REDIRECT_STATUSES, true)) {
                $redirects++;

                if ($redirects > $maxRedirects) {
                    throw new FetchException('Too many redirects.', 'source_unreachable');
                }

                $location = $response->header('Location');

                if (! $location) {
                    throw new FetchException('Redirect response is missing a Location header.', 'source_unreachable');
                }

                $current = $this->urlNormalizer->normalize($this->resolveAbsolute($current, $location));

                continue;
            }

            if ($response->statusCode === 401) {
                throw new FetchException('The website requires authentication.', 'source_requires_authentication');
            }

            if ($response->statusCode === 403 || $response->statusCode === 429) {
                throw new FetchException('The website blocked this request.', 'source_blocked');
            }

            if ($response->statusCode >= 400) {
                throw new FetchException("The website returned an error ({$response->statusCode}).", 'source_unreachable');
            }

            return $this->buildDocument($requestedUrl, $current, $response, $allowedContentTypes, $maxBytes);
        }
    }

    private function send(string $url, string $pinnedIp): TransportResponse
    {
        try {
            return $this->transport->send('GET', $url, [
                'pinned_ip' => $pinnedIp,
                'headers' => ['User-Agent' => (string) config('onboarding.crawl.user_agent')],
                'connect_timeout' => (int) config('onboarding.crawl.connect_timeout_seconds', 5),
                'timeout' => (int) config('onboarding.crawl.request_timeout_seconds', 12),
            ]);
        } catch (Throwable $exception) {
            throw new FetchException('The website could not be reached.', 'source_unreachable');
        }
    }

    /**
     * @param  list<string>  $allowedContentTypes
     */
    private function buildDocument(string $requestedUrl, string $finalUrl, TransportResponse $response, array $allowedContentTypes, int $maxBytes): FetchedDocument
    {
        $contentType = $this->baseContentType($response->header('Content-Type') ?? '');

        if (! in_array($contentType, $allowedContentTypes, true)) {
            throw new FetchException("Unsupported content type [{$contentType}].", 'content_type_unsupported');
        }

        $declaredLength = $response->header('Content-Length');

        if ($declaredLength !== null && ctype_digit($declaredLength) && (int) $declaredLength > $maxBytes) {
            throw new FetchException('The page exceeds the allowed size.', 'fetch_limit_reached');
        }

        $size = strlen($response->body);

        if ($size > $maxBytes) {
            throw new FetchException('The page exceeds the allowed size.', 'fetch_limit_reached');
        }

        return new FetchedDocument(
            requestedUrl: $requestedUrl,
            finalUrl: $finalUrl,
            statusCode: $response->statusCode,
            contentType: $contentType,
            body: $response->body,
            sizeBytes: $size,
        );
    }

    private function baseContentType(string $header): string
    {
        return strtolower(trim(explode(';', $header)[0] ?? ''));
    }

    private function resolveAbsolute(string $base, string $location): string
    {
        $baseScheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';

        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            $targetScheme = parse_url($location, PHP_URL_SCHEME);

            if ($baseScheme === 'https' && $targetScheme === 'http') {
                throw new FetchException('Refusing to follow an https-to-http downgrade redirect.', 'source_blocked');
            }

            return $location;
        }

        $baseParts = parse_url($base);
        $host = $baseParts['host'] ?? '';
        $port = isset($baseParts['port']) ? ':'.$baseParts['port'] : '';

        if (str_starts_with($location, '//')) {
            return "{$baseScheme}:{$location}";
        }

        if (str_starts_with($location, '/')) {
            return "{$baseScheme}://{$host}{$port}{$location}";
        }

        $basePath = $baseParts['path'] ?? '/';

        return "{$baseScheme}://{$host}{$port}{$this->directoryOf($basePath)}/{$location}";
    }

    /**
     * PHP's dirname() is filesystem-oriented and, on Windows, can mangle URL paths
     * (mixing in backslashes) — this is a plain string operation on the URL path
     * instead, portable across platforms.
     */
    private function directoryOf(string $path): string
    {
        $lastSlash = strrpos($path, '/');

        return $lastSlash === false ? '' : substr($path, 0, $lastSlash);
    }
}
