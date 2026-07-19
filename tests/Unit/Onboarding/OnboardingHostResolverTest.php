<?php

namespace Tests\Unit\Onboarding;

use App\Services\Onboarding\DnsOnboardingHostResolver;
use Tests\TestCase;

class OnboardingHostResolverTest extends TestCase
{
    public function test_resolves_an_ip_literal_host_to_itself(): void
    {
        $resolver = new DnsOnboardingHostResolver;

        $this->assertSame(['93.184.216.34'], $resolver->resolve('93.184.216.34'));
    }

    public function test_strips_ipv6_brackets_before_resolving(): void
    {
        $resolver = new DnsOnboardingHostResolver;

        $this->assertSame(['::1'], $resolver->resolve('[::1]'));
    }

    public function test_returns_empty_for_an_unresolvable_host(): void
    {
        $resolver = new DnsOnboardingHostResolver;

        $this->assertSame([], $resolver->resolve('this-host-does-not-exist.invalid'));
    }
}
