<?php

namespace App\Services\Onboarding;

/**
 * Resolves a hostname to the IP address(es) it currently points to. Shared by
 * OnboardingUrlValidator (submission-time validation) and, in Task 2, the fetcher
 * (fetch-time re-resolution + DNS pinning) — both must agree on "what IP would we
 * actually connect to" for SSRF protection to be meaningful.
 */
interface OnboardingHostResolver
{
    /**
     * @return list<string> empty if the host could not be resolved
     */
    public function resolve(string $host): array;
}
