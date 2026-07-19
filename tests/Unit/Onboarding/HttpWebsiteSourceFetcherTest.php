<?php

namespace Tests\Unit\Onboarding;

use App\Exceptions\Onboarding\InvalidOnboardingUrlException;
use App\Services\Onboarding\FakeOnboardingHostResolver;
use App\Services\Onboarding\Fetcher\FetchException;
use App\Services\Onboarding\Fetcher\HttpWebsiteSourceFetcher;
use App\Services\Onboarding\Fetcher\SpyOnboardingHttpTransport;
use App\Services\Onboarding\Fetcher\TransportResponse;
use App\Services\Onboarding\OnboardingUrlNormalizer;
use App\Services\Onboarding\OnboardingUrlValidator;
use Tests\TestCase;
use Throwable;

class HttpWebsiteSourceFetcherTest extends TestCase
{
    private const HTML_TYPES = ['text/html', 'application/xhtml+xml'];

    public function test_homepage_fetch_passes_the_resolved_ip_as_pinned(): void
    {
        $resolver = (new FakeOnboardingHostResolver)->willResolve('93.184.216.34', ['93.184.216.34']);
        $transport = new SpyOnboardingHttpTransport;
        $transport->willRespond('http://93.184.216.34', $this->htmlResponse('http://93.184.216.34', '<html><body>hi</body></html>'));

        $fetcher = $this->fetcher($transport, $resolver);
        $document = $fetcher->fetch('http://93.184.216.34', self::HTML_TYPES, 1_000_000);

        $this->assertSame('<html><body>hi</body></html>', $document->body);
        $this->assertCount(1, $transport->calls);
        $this->assertSame('93.184.216.34', $transport->calls[0]['options']['pinned_ip']);
    }

    public function test_redirect_is_followed_revalidated_and_repinned(): void
    {
        $resolver = (new FakeOnboardingHostResolver)
            ->willResolve('93.184.216.34', ['93.184.216.34'])
            ->willResolve('93.184.216.50', ['93.184.216.50']);

        $transport = new SpyOnboardingHttpTransport;
        $transport->willRespond('http://93.184.216.34', $this->redirectResponse('http://93.184.216.50'));
        $transport->willRespond('http://93.184.216.50', $this->htmlResponse('http://93.184.216.50', '<html>final</html>'));

        $fetcher = $this->fetcher($transport, $resolver);
        $document = $fetcher->fetch('http://93.184.216.34', self::HTML_TYPES, 1_000_000);

        $this->assertSame('http://93.184.216.50', $document->finalUrl);
        $this->assertCount(2, $transport->calls);
        $this->assertSame('93.184.216.34', $transport->calls[0]['options']['pinned_ip']);
        $this->assertSame('93.184.216.50', $transport->calls[1]['options']['pinned_ip']);
    }

    public function test_redirect_to_a_private_ip_is_blocked(): void
    {
        $resolver = (new FakeOnboardingHostResolver)
            ->willResolve('93.184.216.34', ['93.184.216.34'])
            ->willResolve('10.0.0.5', ['10.0.0.5']);

        $transport = new SpyOnboardingHttpTransport;
        $transport->willRespond('http://93.184.216.34', $this->redirectResponse('http://10.0.0.5'));

        $fetcher = $this->fetcher($transport, $resolver);

        // The redirect target is caught by OnboardingUrlValidator's own IP check
        // (invoked again for the new hop) — an InvalidOnboardingUrlException, not a
        // FetchException, is the correct/expected outcome here.
        $this->expectException(InvalidOnboardingUrlException::class);
        $fetcher->fetch('http://93.184.216.34', self::HTML_TYPES, 1_000_000);

        $this->assertCount(1, $transport->calls);
    }

    public function test_dns_rebinding_between_requests_is_caught_by_revalidation(): void
    {
        // First resolve (submission-time-equivalent check) returns a public IP; the
        // resolver is scripted to return a private IP on the next resolution of the
        // same host, simulating a rebind between two separate fetch() calls.
        $resolver = (new FakeOnboardingHostResolver)
            ->willResolveOnce('rebind.example', ['93.184.216.34'])
            ->willResolveOnce('rebind.example', ['127.0.0.1']);

        $transport = new SpyOnboardingHttpTransport;
        $transport->willRespond('https://rebind.example', $this->htmlResponse('https://rebind.example', '<html>ok</html>'));

        $fetcher = $this->fetcher($transport, $resolver);

        // First call succeeds (public IP).
        $fetcher->fetch('https://rebind.example', self::HTML_TYPES, 1_000_000);

        // Second call — resolver now returns a private IP — must be rejected before
        // any request is sent to the transport for it.
        $this->expectException(InvalidOnboardingUrlException::class);
        $fetcher->fetch('https://rebind.example', self::HTML_TYPES, 1_000_000);
    }

    public function test_localhost_and_private_and_metadata_hosts_are_blocked_at_fetch_time(): void
    {
        $transport = new SpyOnboardingHttpTransport;

        foreach (['http://localhost', 'http://127.0.0.1', 'http://169.254.169.254'] as $url) {
            $resolver = new FakeOnboardingHostResolver;
            $host = parse_url($url, PHP_URL_HOST);
            $resolver->willResolve($host, [$host === 'localhost' ? '127.0.0.1' : $host]);

            $fetcher = $this->fetcher($transport, $resolver);

            try {
                $fetcher->fetch($url, self::HTML_TYPES, 1_000_000);
                $this->fail("Expected {$url} to be blocked.");
            } catch (Throwable $exception) {
                $this->assertInstanceOf(InvalidOnboardingUrlException::class, $exception);
            }
        }

        $this->assertSame([], $transport->calls);
    }

    public function test_unsupported_content_type_is_rejected_for_a_page_fetch(): void
    {
        $resolver = (new FakeOnboardingHostResolver)->willResolve('93.184.216.34', ['93.184.216.34']);
        $transport = new SpyOnboardingHttpTransport;
        $transport->willRespond('http://93.184.216.34', new TransportResponse(200, ['content-type' => 'application/pdf'], '%PDF-1.4', 'http://93.184.216.34'));

        $fetcher = $this->fetcher($transport, $resolver);

        try {
            $fetcher->fetch('http://93.184.216.34', self::HTML_TYPES, 1_000_000);
            $this->fail('Expected content_type_unsupported.');
        } catch (FetchException $exception) {
            $this->assertSame('content_type_unsupported', $exception->reasonCode());
        }
    }

    public function test_sitemap_content_type_is_accepted_when_requested(): void
    {
        $resolver = (new FakeOnboardingHostResolver)->willResolve('93.184.216.34', ['93.184.216.34']);
        $transport = new SpyOnboardingHttpTransport;
        $transport->willRespond('http://93.184.216.34/sitemap.xml', new TransportResponse(200, ['content-type' => 'application/xml'], '<urlset></urlset>', 'http://93.184.216.34/sitemap.xml'));

        $fetcher = $this->fetcher($transport, $resolver);
        $document = $fetcher->fetch('http://93.184.216.34/sitemap.xml', ['application/xml', 'text/xml'], 1_000_000);

        $this->assertSame('<urlset></urlset>', $document->body);
    }

    public function test_oversized_page_is_rejected(): void
    {
        $resolver = (new FakeOnboardingHostResolver)->willResolve('93.184.216.34', ['93.184.216.34']);
        $transport = new SpyOnboardingHttpTransport;
        $transport->willRespond('http://93.184.216.34', $this->htmlResponse('http://93.184.216.34', str_repeat('a', 1000)));

        $fetcher = $this->fetcher($transport, $resolver);

        try {
            $fetcher->fetch('http://93.184.216.34', self::HTML_TYPES, 100);
            $this->fail('Expected fetch_limit_reached.');
        } catch (FetchException $exception) {
            $this->assertSame('fetch_limit_reached', $exception->reasonCode());
        }
    }

    public function test_too_many_redirects_are_rejected(): void
    {
        $resolver = new FakeOnboardingHostResolver;
        $transport = new SpyOnboardingHttpTransport;

        for ($i = 0; $i < 10; $i++) {
            $ip = "93.184.216.{$i}";
            $resolver->willResolve($ip, [$ip]);
            $nextIp = '93.184.216.'.($i + 1);
            $transport->willRespond("http://{$ip}", $this->redirectResponse("http://{$nextIp}"));
        }

        config(['onboarding.crawl.max_redirects' => 3]);
        $fetcher = $this->fetcher($transport, $resolver);

        $this->expectException(FetchException::class);
        $fetcher->fetch('http://93.184.216.0', self::HTML_TYPES, 1_000_000);
    }

    private function fetcher(SpyOnboardingHttpTransport $transport, FakeOnboardingHostResolver $resolver): HttpWebsiteSourceFetcher
    {
        return new HttpWebsiteSourceFetcher(
            $transport,
            new OnboardingUrlValidator($resolver),
            new OnboardingUrlNormalizer,
        );
    }

    private function htmlResponse(string $url, string $body): TransportResponse
    {
        return new TransportResponse(200, ['content-type' => 'text/html; charset=utf-8'], $body, $url);
    }

    private function redirectResponse(string $location): TransportResponse
    {
        return new TransportResponse(302, ['location' => $location], '', $location);
    }
}
