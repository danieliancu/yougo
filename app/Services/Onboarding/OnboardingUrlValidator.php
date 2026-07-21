<?php

namespace App\Services\Onboarding;

use App\Exceptions\Onboarding\InvalidOnboardingUrlException;

/**
 * SSRF-safe URL validation, strictly separate from any fetcher — no fetcher exists in
 * Task 1 (neither analyzer performs real HTTP I/O). Validated against the NORMALIZED
 * URL (OnboardingUrlNormalizer::normalize()), applied once at submission time.
 *
 * DNS-rebinding residual risk: this check happens once, here, at submission time. Any
 * future real fetcher (Task 2+) MUST re-resolve and pin the IP immediately before
 * connecting (e.g. curl's CURLOPT_RESOLVE) — it must never trust this check alone,
 * since the host could resolve to a different (private) IP by the time it's fetched.
 */
class OnboardingUrlValidator
{
    private const BLOCKED_HOSTS = ['localhost', '0.0.0.0'];

    private const BLOCKED_HOST_SUFFIXES = ['.localhost', '.local', '.internal'];

    private const EXPLICIT_METADATA_IPS = ['169.254.169.254', 'fd00:ec2::254'];

    private readonly OnboardingHostResolver $hostResolver;

    public function __construct(?OnboardingHostResolver $hostResolver = null)
    {
        $this->hostResolver = $hostResolver ?? new DnsOnboardingHostResolver;
    }

    public function validate(string $normalizedUrl): void
    {
        $this->resolveValidatedIps($normalizedUrl);
    }

    /**
     * Same checks as validate(), but also returns the resolved, already-validated IP
     * list — lets a caller that needs an IP right after validating (the fetcher, to
     * pin the connection) reuse this single resolution instead of resolving the host
     * a second time, which would both double DNS traffic and reopen a small
     * validate-then-resolve-again TOCTOU gap of its own.
     *
     * @return list<string>
     */
    public function resolveValidatedIps(string $normalizedUrl): array
    {
        $maxLength = (int) config('onboarding.url.max_length', 2048);

        if (strlen($normalizedUrl) > $maxLength) {
            throw new InvalidOnboardingUrlException('The provided URL is too long.', 'url_too_long');
        }

        $parts = parse_url($normalizedUrl);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new InvalidOnboardingUrlException('The provided URL could not be parsed.', 'unparseable');
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidOnboardingUrlException('Only http and https URLs are allowed.', 'invalid_scheme');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidOnboardingUrlException('URLs with embedded credentials are not allowed.', 'credentials_in_url');
        }

        $host = strtolower($parts['host']);

        if ($host === '') {
            throw new InvalidOnboardingUrlException('The provided URL has no host.', 'missing_host');
        }

        if (in_array($host, self::BLOCKED_HOSTS, true)) {
            throw new InvalidOnboardingUrlException('This host is not allowed.', 'blocked_host');
        }

        foreach (self::BLOCKED_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                throw new InvalidOnboardingUrlException('This host is not allowed.', 'blocked_host');
            }
        }

        $ips = $this->hostResolver->resolve($host);

        if ($ips === []) {
            throw new InvalidOnboardingUrlException('The host could not be resolved.', 'dns_resolution_failed');
        }

        foreach ($ips as $ip) {
            if ($this->isDisallowedIp($ip)) {
                throw new InvalidOnboardingUrlException('This host resolves to a disallowed network address.', 'disallowed_ip');
            }
        }

        return $ips;
    }

    private function isDisallowedIp(string $ip): bool
    {
        // Testing-only escape hatch (config/onboarding.php: crawl.allow_private_networks_for_testing,
        // hardcoded false outside the `testing` environment) so a loopback smoke test can exercise the
        // real fetch/transport stack against a local server without touching public internet. Never
        // read by any request-facing code path.
        if (app()->environment('testing') && config('onboarding.crawl.allow_private_networks_for_testing') === true) {
            return false;
        }

        if (in_array($ip, self::EXPLICIT_METADATA_IPS, true)) {
            return true;
        }

        if (str_starts_with(strtolower($ip), 'fe80')) {
            return true;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
