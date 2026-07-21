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

    public function test_excludes_legal_and_boilerplate_pages(): void
    {
        // A real import lost every service price because the AI call budget got spent
        // on a long terms-and-conditions page (dense-page splitting turned it into many
        // calls on its own) before ever reaching the actual /servicii/ page. Legal pages
        // carry no business info and must never be crawled at all.
        $fetcher = new FakeOnboardingSourceFetcher;
        $fetcher->willReturnHtml('https://salon.ro', $this->pageWithLinks([
            'https://salon.ro/termeni-si-conditii',
            'https://salon.ro/politica-de-confidentialitate',
            'https://salon.ro/politica-de-cookies',
            'https://salon.ro/regulamentul-general-pentru-protectia-datelor-gdpr',
            'https://salon.ro/servicii',
        ]));
        $fetcher->willReturnHtml('https://salon.ro/servicii', '<html><body>servicii</body></html>');

        $crawler = $this->crawler($fetcher);
        $result = $crawler->crawl('https://salon.ro');

        $urls = array_map(fn ($p) => $p->url, $result->pages);
        $this->assertNotContains('https://salon.ro/termeni-si-conditii', $urls);
        $this->assertNotContains('https://salon.ro/politica-de-confidentialitate', $urls);
        $this->assertNotContains('https://salon.ro/politica-de-cookies', $urls);
        $this->assertNotContains('https://salon.ro/regulamentul-general-pentru-protectia-datelor-gdpr', $urls);
        $this->assertContains('https://salon.ro/servicii', $urls);
    }

    public function test_excludes_blog_articles_detected_by_breadcrumb(): void
    {
        // Blog articles are often published at a bare root-level slug with no shared
        // URL prefix (no /blog/ in the path at all) — a real import extracted "services"
        // straight out of such articles (a technique mentioned in passing, no price).
        // A breadcrumb trail through a blog-like section is a structural signal that
        // survives even then (unlike og:type, which real business pages set too on some
        // WordPress themes — see looksLikeBlogArticle()'s docblock).
        $fetcher = new FakeOnboardingSourceFetcher;
        $fetcher->willReturnHtml('https://salon.ro', $this->pageWithLinks([
            'https://salon.ro/tips-ingrijire-par',
            'https://salon.ro/servicii',
        ]));
        $fetcher->willReturnHtml('https://salon.ro/tips-ingrijire-par', '<html><body><nav aria-label="breadcrumb"><li>Acasa</li><li>Blog</li><li>Tips</li></nav>continut</body></html>');
        $fetcher->willReturnHtml('https://salon.ro/servicii', '<html><body>servicii</body></html>');

        $crawler = $this->crawler($fetcher);
        $result = $crawler->crawl('https://salon.ro');

        $urls = array_map(fn ($p) => $p->url, $result->pages);
        $this->assertNotContains('https://salon.ro/tips-ingrijire-par', $urls);
        $this->assertContains('https://salon.ro/servicii', $urls);
    }

    public function test_does_not_exclude_business_pages_whose_theme_sets_og_type_article(): void
    {
        // Regression guard for the og:type false-positive discovered against a real
        // site: its theme set og:type="article" on every page, not just blog posts —
        // og:type is no longer used for this decision at all (see hasArticleSchema()).
        $fetcher = new FakeOnboardingSourceFetcher;
        $fetcher->willReturnHtml('https://salon.ro', $this->pageWithLinks(['https://salon.ro/preturi']));
        $fetcher->willReturnHtml('https://salon.ro/preturi', '<html><head><meta property="og:type" content="article"></head><body>preturi si servicii</body></html>');

        $crawler = $this->crawler($fetcher);
        $result = $crawler->crawl('https://salon.ro');

        $urls = array_map(fn ($p) => $p->url, $result->pages);
        $this->assertContains('https://salon.ro/preturi', $urls);
    }

    public function test_excludes_blog_articles_detected_by_json_ld_blog_posting_schema(): void
    {
        // The signal that actually caught the real article that had no breadcrumb at
        // all: schema.org BlogPosting JSON-LD, emitted by Yoast/Rank Math for posts.
        $fetcher = new FakeOnboardingSourceFetcher;
        $fetcher->willReturnHtml('https://salon.ro', $this->pageWithLinks([
            'https://salon.ro/ce-este-balayage',
            'https://salon.ro/servicii',
        ]));
        $fetcher->willReturnHtml(
            'https://salon.ro/ce-este-balayage',
            '<html><head><script type="application/ld+json">{"@type":"BlogPosting","headline":"Ce este balayage"}</script></head><body>articol</body></html>'
        );
        $fetcher->willReturnHtml('https://salon.ro/servicii', '<html><body>servicii</body></html>');

        $crawler = $this->crawler($fetcher);
        $result = $crawler->crawl('https://salon.ro');

        $urls = array_map(fn ($p) => $p->url, $result->pages);
        $this->assertNotContains('https://salon.ro/ce-este-balayage', $urls);
        $this->assertContains('https://salon.ro/servicii', $urls);
    }

    public function test_excludes_blog_articles_detected_by_wordpress_single_post_body_class(): void
    {
        // The signal that caught a real article with neither a breadcrumb nor JSON-LD
        // BlogPosting at all: WordPress core's own body_class() output. "single-post" is
        // added by WordPress itself (not a theme or SEO plugin) only on a singular view
        // of the built-in "post" type — pages, including og:type="article" ones, get
        // "page page-template-default" instead.
        $fetcher = new FakeOnboardingSourceFetcher;
        $fetcher->willReturnHtml('https://salon.ro', $this->pageWithLinks([
            'https://salon.ro/ritual-ingrijire-par',
            'https://salon.ro/servicii',
        ]));
        $fetcher->willReturnHtml(
            'https://salon.ro/ritual-ingrijire-par',
            '<html><body class="wp-singular post-template-default single single-post postid-42 single-format-standard">articol</body></html>'
        );
        $fetcher->willReturnHtml(
            'https://salon.ro/servicii',
            '<html><body class="wp-singular page-template-default page page-id-7">servicii</body></html>'
        );

        $crawler = $this->crawler($fetcher);
        $result = $crawler->crawl('https://salon.ro');

        $urls = array_map(fn ($p) => $p->url, $result->pages);
        $this->assertNotContains('https://salon.ro/ritual-ingrijire-par', $urls);
        $this->assertContains('https://salon.ro/servicii', $urls);
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

    public function test_sitemap_index_children_are_fetched_as_xml_and_their_urls_queued(): void
    {
        $fetcher = new FakeOnboardingSourceFetcher;
        $fetcher->willReturnHtml('https://salon.ro', '<html><body>home</body></html>');
        $indexXml = '<?xml version="1.0"?><sitemapindex>'
            .'<sitemap><loc>https://salon.ro/sitemap-pages.xml</loc></sitemap>'
            .'</sitemapindex>';
        $fetcher->willReturnDocument('https://salon.ro/sitemap.xml', new FetchedDocument(
            'https://salon.ro/sitemap.xml', 'https://salon.ro/sitemap.xml', 200, 'application/xml', $indexXml, strlen($indexXml)
        ));
        $childXml = '<?xml version="1.0"?><urlset><url><loc>https://salon.ro/servicii</loc></url></urlset>';
        $fetcher->willReturnDocument('https://salon.ro/sitemap-pages.xml', new FetchedDocument(
            'https://salon.ro/sitemap-pages.xml', 'https://salon.ro/sitemap-pages.xml', 200, 'application/xml', $childXml, strlen($childXml)
        ));
        $fetcher->willReturnHtml('https://salon.ro/servicii', '<html><body>servicii</body></html>');

        $crawler = $this->crawler($fetcher);
        $result = $crawler->crawl('https://salon.ro');

        $urls = array_map(fn ($p) => $p->url, $result->pages);
        $this->assertContains('https://salon.ro/servicii', $urls);
        // The child sitemap URL itself must never be queued as an HTML page candidate.
        $this->assertNotContains('https://salon.ro/sitemap-pages.xml', $urls);
    }

    public function test_wp_sitemap_is_used_when_the_first_two_paths_are_unavailable(): void
    {
        $fetcher = new FakeOnboardingSourceFetcher;
        $fetcher->willReturnHtml('https://salon.ro', '<html><body>home</body></html>');
        $fetcher->willFail('https://salon.ro/sitemap.xml', new FetchException('missing', 'source_unreachable'));
        $fetcher->willFail('https://salon.ro/sitemap_index.xml', new FetchException('missing', 'source_unreachable'));
        $sitemapXml = '<?xml version="1.0"?><urlset><url><loc>https://salon.ro/servicii</loc></url></urlset>';
        $fetcher->willReturnDocument('https://salon.ro/wp-sitemap.xml', new FetchedDocument(
            'https://salon.ro/wp-sitemap.xml', 'https://salon.ro/wp-sitemap.xml', 200, 'application/xml', $sitemapXml, strlen($sitemapXml)
        ));
        $fetcher->willReturnHtml('https://salon.ro/servicii', '<html><body>servicii</body></html>');

        $crawler = $this->crawler($fetcher);
        $result = $crawler->crawl('https://salon.ro');

        $urls = array_map(fn ($p) => $p->url, $result->pages);
        $this->assertContains('https://salon.ro/servicii', $urls);
    }

    public function test_sitemap_index_respects_max_sitemap_files_limit(): void
    {
        config(['onboarding.crawl.max_sitemap_files' => 1]);

        $fetcher = new FakeOnboardingSourceFetcher;
        $fetcher->willReturnHtml('https://salon.ro', '<html><body>home</body></html>');
        $indexXml = '<?xml version="1.0"?><sitemapindex>'
            .'<sitemap><loc>https://salon.ro/sitemap-a.xml</loc></sitemap>'
            .'<sitemap><loc>https://salon.ro/sitemap-b.xml</loc></sitemap>'
            .'</sitemapindex>';
        $fetcher->willReturnDocument('https://salon.ro/sitemap.xml', new FetchedDocument(
            'https://salon.ro/sitemap.xml', 'https://salon.ro/sitemap.xml', 200, 'application/xml', $indexXml, strlen($indexXml)
        ));
        $aXml = '<?xml version="1.0"?><urlset><url><loc>https://salon.ro/servicii</loc></url></urlset>';
        $fetcher->willReturnDocument('https://salon.ro/sitemap-a.xml', new FetchedDocument(
            'https://salon.ro/sitemap-a.xml', 'https://salon.ro/sitemap-a.xml', 200, 'application/xml', $aXml, strlen($aXml)
        ));
        $bXml = '<?xml version="1.0"?><urlset><url><loc>https://salon.ro/contact</loc></url></urlset>';
        $fetcher->willReturnDocument('https://salon.ro/sitemap-b.xml', new FetchedDocument(
            'https://salon.ro/sitemap-b.xml', 'https://salon.ro/sitemap-b.xml', 200, 'application/xml', $bXml, strlen($bXml)
        ));
        $fetcher->willReturnHtml('https://salon.ro/servicii', '<html><body>servicii</body></html>');
        $fetcher->willReturnHtml('https://salon.ro/contact', '<html><body>contact</body></html>');

        $crawler = $this->crawler($fetcher);
        $result = $crawler->crawl('https://salon.ro');

        $urls = array_map(fn ($p) => $p->url, $result->pages);
        $this->assertContains('https://salon.ro/servicii', $urls);
        $this->assertNotContains('https://salon.ro/contact', $urls);
    }

    public function test_sitemap_urlset_respects_max_sitemap_urls_limit(): void
    {
        config(['onboarding.crawl.max_sitemap_urls' => 1]);

        $fetcher = new FakeOnboardingSourceFetcher;
        $fetcher->willReturnHtml('https://salon.ro', '<html><body>home</body></html>');
        $sitemapXml = '<?xml version="1.0"?><urlset>'
            .'<url><loc>https://salon.ro/servicii</loc></url>'
            .'<url><loc>https://salon.ro/contact</loc></url>'
            .'</urlset>';
        $fetcher->willReturnDocument('https://salon.ro/sitemap.xml', new FetchedDocument(
            'https://salon.ro/sitemap.xml', 'https://salon.ro/sitemap.xml', 200, 'application/xml', $sitemapXml, strlen($sitemapXml)
        ));
        $fetcher->willReturnHtml('https://salon.ro/servicii', '<html><body>servicii</body></html>');
        $fetcher->willReturnHtml('https://salon.ro/contact', '<html><body>contact</body></html>');

        $crawler = $this->crawler($fetcher);
        $result = $crawler->crawl('https://salon.ro');

        $urls = array_map(fn ($p) => $p->url, $result->pages);
        $this->assertContains('https://salon.ro/servicii', $urls);
        $this->assertNotContains('https://salon.ro/contact', $urls);
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
