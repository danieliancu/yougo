<?php

namespace Tests\Unit\Onboarding;

use App\Services\Onboarding\Crawler\OnboardingWebsiteCrawler;
use App\Services\Onboarding\Extraction\OnboardingPageContentExtractor;
use App\Services\Onboarding\Fetcher\FakeOnboardingSourceFetcher;
use App\Services\Onboarding\Fetcher\FetchedDocument;
use App\Services\Onboarding\Fetcher\FetchException;
use App\Services\Onboarding\OnboardingUrlNormalizer;
use Tests\TestCase;

class OnboardingWebsiteCrawlerTest extends TestCase
{
    public function test_respects_the_max_pages_limit(): void
    {
        config(['onboarding.crawl.max_pages' => 2, 'onboarding.crawl.max_depth' => 1]);

        $fetcher = new FakeOnboardingSourceFetcher;
        $fetcher->willReturnHtml('https://salon.ro', $this->pageWithLinks([
            'https://salon.ro/servicii', 'https://salon.ro/contact', 'https://salon.ro/despre',
        ]));
        $fetcher->willReturnHtml('https://salon.ro/servicii', '<html><body>servicii</body></html>');
        $fetcher->willReturnHtml('https://salon.ro/contact', '<html><body>contact</body></html>');
        $fetcher->willReturnHtml('https://salon.ro/despre', '<html><body>despre</body></html>');

        $crawler = $this->crawler($fetcher);
        $result = $crawler->crawl('https://salon.ro');

        $this->assertCount(2, $result->pages);
        $this->assertSame('max_pages', $result->stopReason);
    }

    public function test_deduplicates_repeated_urls(): void
    {
        $fetcher = new FakeOnboardingSourceFetcher;
        $fetcher->willReturnHtml('https://salon.ro', $this->pageWithLinks([
            'https://salon.ro/servicii', 'https://salon.ro/servicii',
        ]));
        $fetcher->willReturnHtml('https://salon.ro/servicii', '<html><body>servicii</body></html>');

        $crawler = $this->crawler($fetcher);
        $result = $crawler->crawl('https://salon.ro');

        $this->assertCount(2, $result->pages);
        // Each URL was only ever actually fetched once, even though it was linked twice.
        $this->assertCount(count($fetcher->requestedUrls), array_unique($fetcher->requestedUrls));
    }

    public function test_external_links_are_ignored(): void
    {
        $fetcher = new FakeOnboardingSourceFetcher;
        $fetcher->willReturnHtml('https://salon.ro', $this->pageWithLinks([
            'https://other-business.ro/', 'https://salon.ro/servicii',
        ]));
        $fetcher->willReturnHtml('https://salon.ro/servicii', '<html><body>servicii</body></html>');

        $crawler = $this->crawler($fetcher);
        $result = $crawler->crawl('https://salon.ro');

        $urls = array_map(fn ($p) => $p->url, $result->pages);
        $this->assertNotContains('https://other-business.ro', $urls);
        $this->assertContains('https://salon.ro', array_map(fn ($p) => $p->url, $result->pages));
    }

    public function test_www_variant_allowed_but_unrelated_subdomain_rejected(): void
    {
        $fetcher = new FakeOnboardingSourceFetcher;
        $fetcher->willReturnHtml('https://salon.ro', $this->pageWithLinks([
            'https://www.salon.ro/servicii', 'https://blog.salon.ro/post',
        ]));
        $fetcher->willReturnHtml('https://www.salon.ro/servicii', '<html><body>servicii</body></html>');

        $crawler = $this->crawler($fetcher);
        $result = $crawler->crawl('https://salon.ro');

        $urls = array_map(fn ($p) => $p->url, $result->pages);
        $this->assertContains('https://www.salon.ro/servicii', $urls);
        $this->assertNotContains('https://blog.salon.ro/post', $urls);
        $this->assertContains('https://blog.salon.ro/post', $result->ignoredUrls);
    }

    public function test_excludes_login_cart_pagination_and_tracking_param_urls(): void
    {
        $fetcher = new FakeOnboardingSourceFetcher;
        $fetcher->willReturnHtml('https://salon.ro', $this->pageWithLinks([
            'https://salon.ro/login',
            'https://salon.ro/cart',
            'https://salon.ro/blog/page/2',
            'https://salon.ro/servicii?utm_source=fb&ref=1',
        ]));
        $fetcher->willReturnHtml('https://salon.ro/servicii?ref=1', '<html><body>servicii</body></html>');

        $crawler = $this->crawler($fetcher);
        $result = $crawler->crawl('https://salon.ro');

        $urls = array_map(fn ($p) => $p->url, $result->pages);
        $this->assertNotContains('https://salon.ro/login', $urls);
        $this->assertNotContains('https://salon.ro/cart', $urls);
        $this->assertNotContains('https://salon.ro/blog/page/2', $urls);
        // The tracking param (utm_source) must be stripped, the non-tracking param (ref) kept.
        $this->assertContains('https://salon.ro/servicii?ref=1', $urls);
    }

    public function test_sitemap_is_parsed_and_its_urls_are_queued(): void
    {
        $fetcher = new FakeOnboardingSourceFetcher;
        $fetcher->willReturnHtml('https://salon.ro', '<html><body>home</body></html>');
        $sitemapXml = '<?xml version="1.0"?><urlset><url><loc>https://salon.ro/servicii</loc></url></urlset>';
        $fetcher->willReturnDocument('https://salon.ro/sitemap.xml', new FetchedDocument(
            'https://salon.ro/sitemap.xml', 'https://salon.ro/sitemap.xml', 200, 'application/xml', $sitemapXml, strlen($sitemapXml)
        ));
        $fetcher->willReturnHtml('https://salon.ro/servicii', '<html><body>servicii</body></html>');

        $crawler = $this->crawler($fetcher);
        $result = $crawler->crawl('https://salon.ro');

        $urls = array_map(fn ($p) => $p->url, $result->pages);
        $this->assertContains('https://salon.ro/servicii', $urls);
    }

    public function test_services_and_contact_keyword_pages_are_prioritized(): void
    {
        config(['onboarding.crawl.max_pages' => 2, 'onboarding.crawl.max_depth' => 1]);

        $fetcher = new FakeOnboardingSourceFetcher;
        $fetcher->willReturnHtml('https://salon.ro', $this->pageWithLinks([
            'https://salon.ro/blog/random-post-title',
            'https://salon.ro/servicii',
        ]));
        $fetcher->willReturnHtml('https://salon.ro/blog/random-post-title', '<html><body>x</body></html>');
        $fetcher->willReturnHtml('https://salon.ro/servicii', '<html><body>x</body></html>');

        $crawler = $this->crawler($fetcher);
        $result = $crawler->crawl('https://salon.ro');

        $urls = array_map(fn ($p) => $p->url, $result->pages);
        $this->assertContains('https://salon.ro/servicii', $urls);
        $this->assertNotContains('https://salon.ro/blog/random-post-title', $urls);
    }

    public function test_blocked_homepage_yields_zero_pages_with_a_warning(): void
    {
        $fetcher = new FakeOnboardingSourceFetcher;
        $fetcher->willFail('https://salon.ro', new FetchException('blocked', 'source_blocked'));

        $crawler = $this->crawler($fetcher);
        $result = $crawler->crawl('https://salon.ro');

        $this->assertCount(0, $result->pages);
        $this->assertNotEmpty($result->warnings);
        $this->assertTrue(
            collect($result->warnings)->contains(fn (string $warning) => str_contains($warning, 'source_blocked')),
            'Expected a source_blocked warning among: '.implode(', ', $result->warnings)
        );
    }

    private function crawler(FakeOnboardingSourceFetcher $fetcher): OnboardingWebsiteCrawler
    {
        return new OnboardingWebsiteCrawler($fetcher, new OnboardingPageContentExtractor, new OnboardingUrlNormalizer);
    }

    /**
     * @param  list<string>  $links
     */
    private function pageWithLinks(array $links): string
    {
        $anchors = implode('', array_map(fn ($url) => "<a href=\"{$url}\">link</a>", $links));

        return "<html><body>{$anchors}</body></html>";
    }
}
