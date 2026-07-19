<?php

namespace Tests\Unit\Onboarding;

use App\Exceptions\Onboarding\InvalidOnboardingUrlException;
use App\Services\Onboarding\OnboardingUrlValidator;
use Tests\TestCase;

class OnboardingUrlValidatorTest extends TestCase
{
    public function test_accepts_a_safe_public_url(): void
    {
        $validator = new OnboardingUrlValidator;

        // IP literal so this doesn't depend on real DNS resolution in CI.
        $validator->validate('http://93.184.216.34/');

        $this->addToAssertionCount(1);
    }

    public function test_rejects_oversized_url(): void
    {
        $validator = new OnboardingUrlValidator;

        $this->expectExceptionObjectMatches($validator, 'http://93.184.216.34/'.str_repeat('a', 3000), 'url_too_long');
    }

    public function test_rejects_unparseable_url(): void
    {
        $validator = new OnboardingUrlValidator;

        $this->expectExceptionObjectMatches($validator, 'not a url', 'unparseable');
    }

    public function test_rejects_non_http_scheme(): void
    {
        $validator = new OnboardingUrlValidator;

        $this->expectExceptionObjectMatches($validator, 'ftp://93.184.216.34/', 'invalid_scheme');
    }

    public function test_rejects_credentials_in_url(): void
    {
        $validator = new OnboardingUrlValidator;

        $this->expectExceptionObjectMatches($validator, 'http://user:pass@93.184.216.34/', 'credentials_in_url');
    }

    public function test_rejects_localhost_variants(): void
    {
        $validator = new OnboardingUrlValidator;

        $this->expectExceptionObjectMatches($validator, 'http://localhost/', 'blocked_host');
        $this->expectExceptionObjectMatches($validator, 'http://sub.localhost/', 'blocked_host');
        $this->expectExceptionObjectMatches($validator, 'http://0.0.0.0/', 'blocked_host');
        $this->expectExceptionObjectMatches($validator, 'http://intranet.local/', 'blocked_host');
        $this->expectExceptionObjectMatches($validator, 'http://app.internal/', 'blocked_host');
    }

    public function test_rejects_loopback_ip(): void
    {
        $validator = new OnboardingUrlValidator;

        $this->expectExceptionObjectMatches($validator, 'http://127.0.0.1/', 'disallowed_ip');
        $this->expectExceptionObjectMatches($validator, 'http://[::1]/', 'disallowed_ip');
    }

    public function test_rejects_private_ipv4(): void
    {
        $validator = new OnboardingUrlValidator;

        $this->expectExceptionObjectMatches($validator, 'http://10.0.0.5/', 'disallowed_ip');
        $this->expectExceptionObjectMatches($validator, 'http://172.16.0.5/', 'disallowed_ip');
        $this->expectExceptionObjectMatches($validator, 'http://192.168.1.5/', 'disallowed_ip');
    }

    public function test_rejects_link_local_and_metadata_ip(): void
    {
        $validator = new OnboardingUrlValidator;

        $this->expectExceptionObjectMatches($validator, 'http://169.254.169.254/', 'disallowed_ip');
        $this->expectExceptionObjectMatches($validator, 'http://169.254.1.1/', 'disallowed_ip');
    }

    private function expectExceptionObjectMatches(OnboardingUrlValidator $validator, string $url, string $expectedReasonCode): void
    {
        try {
            $validator->validate($url);
            $this->fail("Expected InvalidOnboardingUrlException with reason [{$expectedReasonCode}] for URL [{$url}].");
        } catch (InvalidOnboardingUrlException $exception) {
            $this->assertSame($expectedReasonCode, $exception->reasonCode());
        }
    }
}
