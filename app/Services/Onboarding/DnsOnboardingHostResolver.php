<?php

namespace App\Services\Onboarding;

/**
 * Default OnboardingHostResolver — moved out of OnboardingUrlValidator unchanged
 * (behavior-preserving extraction) so the fetcher can reuse the exact same
 * resolution logic instead of re-implementing it.
 */
class DnsOnboardingHostResolver implements OnboardingHostResolver
{
    public function resolve(string $host): array
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
}
