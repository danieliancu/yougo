<?php

namespace App\Services\Onboarding\Crawler;

/**
 * MVP host scoping: allows only the exact entry host and its `www.` variant — not a
 * general "registrable domain" computation (which needs a public-suffix-list to be
 * correct; a naive suffix heuristic would wrongly treat every `*.co.uk` site as one
 * domain). No other subdomain is crawled automatically, even if linked. Deliberate,
 * documented limitation — revisit with a real PSL dependency if broader subdomain
 * coverage is wanted later.
 */
final readonly class OnboardingWebsiteHostAllowlist
{
    /**
     * @var list<string>
     */
    private array $allowedHosts;

    public function __construct(string $entryUrl)
    {
        $host = strtolower((string) parse_url($entryUrl, PHP_URL_HOST));
        $this->allowedHosts = $this->variants($host);
    }

    public function allows(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host !== '' && in_array($host, $this->allowedHosts, true);
    }

    /**
     * @return list<string>
     */
    private function variants(string $host): array
    {
        if ($host === '') {
            return [];
        }

        if (str_starts_with($host, 'www.')) {
            return [$host, substr($host, 4)];
        }

        return [$host, 'www.'.$host];
    }
}
