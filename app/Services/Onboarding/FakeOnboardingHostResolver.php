<?php

namespace App\Services\Onboarding;

/**
 * Deterministic, no-DNS OnboardingHostResolver used in tests — lets a test script
 * exactly which IP(s) a host "resolves to" on each successive call, which is what
 * makes DNS-rebinding-between-requests scenarios reproducible without real DNS.
 */
class FakeOnboardingHostResolver implements OnboardingHostResolver
{
    /**
     * @var array<string, list<list<string>>>
     */
    private array $queuedResultsByHost = [];

    /**
     * @var array<string, list<string>>
     */
    private array $defaultResultsByHost = [];

    /**
     * @param  list<string>  $ips
     */
    public function willResolve(string $host, array $ips): static
    {
        $this->defaultResultsByHost[strtolower($host)] = $ips;

        return $this;
    }

    /**
     * Queues a one-time result for the next resolve() call for this host, before
     * falling back to willResolve()'s default — used to simulate a host resolving to
     * a different IP on a later call (DNS rebinding between requests).
     *
     * @param  list<string>  $ips
     */
    public function willResolveOnce(string $host, array $ips): static
    {
        $this->queuedResultsByHost[strtolower($host)][] = $ips;

        return $this;
    }

    public function resolve(string $host): array
    {
        $key = strtolower($host);

        if (! empty($this->queuedResultsByHost[$key])) {
            return array_shift($this->queuedResultsByHost[$key]);
        }

        return $this->defaultResultsByHost[$key] ?? [];
    }
}
