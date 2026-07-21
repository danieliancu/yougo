<?php

namespace Tests\Unit\Onboarding;

use App\Services\Onboarding\OnboardingUrlNormalizer;
use Tests\TestCase;

class OnboardingUrlNormalizerTest extends TestCase
{
    public function test_lowercases_scheme_and_host(): void
    {
        $normalizer = new OnboardingUrlNormalizer;

        $this->assertSame('https://example.com', $normalizer->normalize('HTTPS://EXAMPLE.com'));
    }

    public function test_strips_default_ports(): void
    {
        $normalizer = new OnboardingUrlNormalizer;

        $this->assertSame('https://example.com', $normalizer->normalize('https://example.com:443'));
        $this->assertSame('http://example.com', $normalizer->normalize('http://example.com:80'));
        $this->assertSame('http://example.com:8080', $normalizer->normalize('http://example.com:8080'));
    }

    public function test_strips_fragment(): void
    {
        $normalizer = new OnboardingUrlNormalizer;

        $this->assertSame('https://example.com/page', $normalizer->normalize('https://example.com/page#section'));
    }

    public function test_collapses_bare_path(): void
    {
        $normalizer = new OnboardingUrlNormalizer;

        $this->assertSame('https://example.com', $normalizer->normalize('https://example.com/'));
    }

    public function test_sorts_query_parameters_without_dropping_them(): void
    {
        $normalizer = new OnboardingUrlNormalizer;

        $this->assertSame(
            'https://example.com/page?a=1&b=2',
            $normalizer->normalize('https://example.com/page?b=2&a=1')
        );
    }

    public function test_www_and_non_www_are_not_merged(): void
    {
        $normalizer = new OnboardingUrlNormalizer;

        $this->assertNotSame(
            $normalizer->normalize('https://example.com'),
            $normalizer->normalize('https://www.example.com')
        );
    }

    public function test_leaves_unparseable_urls_unchanged(): void
    {
        $normalizer = new OnboardingUrlNormalizer;

        $this->assertSame('not a url', $normalizer->normalize('not a url'));
    }
}
