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

    public function validate(string $normalizedUrl): void
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

        $ips = $this->resolveIps($host);

        if ($ips === []) {
            throw new InvalidOnboardingUrlException('The host could not be resolved.', 'dns_resolution_failed');
        }

        foreach ($ips as $ip) {
            if ($this->isDisallowedIp($ip)) {
                throw new InvalidOnboardingUrlException('This host resolves to a disallowed network address.', 'disallowed_ip');
            }
        }
    }

    /**
     * @return list<string>
     */
    private function resolveIps(string $host): array
    {
        // PHP's parse_url keeps the enclosing brackets on an IPv6 literal host ("[::1]").
        $host = (str_starts_with($host, '[') && str_ends_with($host, ']')) ? substr($host, 1, -1) : $host;

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);

        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip'])) {
                    $ips[] = $record['ip'];
                } elseif (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        if ($ips === []) {
            $fallback = @gethostbyname($host);

            if (is_string($fallback) && $fallback !== $host) {
                $ips[] = $fallback;
            }
        }

        return array_values(array_unique($ips));
    }

    private function isDisallowedIp(string $ip): bool
    {
        if (in_array($ip, self::EXPLICIT_METADATA_IPS, true)) {
            return true;
        }

        if (str_starts_with(strtolower($ip), 'fe80')) {
            return true;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
