<?php

namespace App\Services\Onboarding\Crawler;

use App\Services\Onboarding\Extraction\ExtractedPage;
use App\Services\Onboarding\Extraction\OnboardingPageContentExtractor;
use App\Services\Onboarding\Fetcher\FetchException;
use App\Services\Onboarding\Fetcher\OnboardingSourceFetcher;
use App\Services\Onboarding\OnboardingUrlNormalizer;

/**
 * Discovers and fetches the pages worth analyzing on a business website: starts at
 * the homepage, opportunistically reads a sitemap, follows same-host links up to a
 * bounded depth, and prioritizes candidates that look like services/pricing/contact/
 * hours/about pages. Stops controlledly (never fails) once any configured limit is
 * hit — whatever was already fetched is kept.
 */
class OnboardingWebsiteCrawler
{
    private const HTML_TYPES = ['text/html', 'application/xhtml+xml'];

    private const SITEMAP_TYPES = ['application/xml', 'text/xml'];

    private const EXCLUDED_PATH_PATTERNS = [
        '#/(login|admin|wp-admin|signin|sign-in)(/|$)#i',
        '#/(cart|checkout|basket)(/|$)#i',
        '#/page/\d+#i',
        '#[?&]page=#i',
        '#/(tag|author)/#i',
        '#[?&]s=#i',
        '#/search(/|$)#i',
        '#/calendar(/|$)#i',
        '#\.(jpe?g|png|gif|svg|webp|pdf|zip|rar|docx?|xlsx?|mp4|mp3|ico|css|js)(\?|$)#i',
        // Legal/boilerplate pages: no business info, but can be long enough (a full T&C
        // document) to eat most of the AI call budget on their own via the dense-page
        // splitting in GeminiWebsiteOnboardingAnalyzer — starving out the actual
        // services/pricing pages before they're ever analyzed. Never worth crawling.
        '#/(termeni-si-conditii|termeni|terms-and-conditions|terms-of-service|termsofservice|tos)(/|$)#i',
        '#/(politica-de-confidentialitate|politica-confidentialitate|privacy-policy|privacypolicy)(/|$)#i',
        '#/(politica-de-cookies|politica-cookies|cookie-policy|cookiepolicy|cookies)(/|$)#i',
        '#/(regulamentul-general-pentru-protectia-datelor|gdpr)(-[\w-]*)?(/|$)#i',
        '#/(mentiuni-legale|legal-notice|imprint|disclaimer)(/|$)#i',
        // Blog/news pages: editorial content, not a service/price list. A real import
        // extracted "services" straight out of blog articles (technique explainers
        // mentioning a service by name in passing) with no price, since that page never
        // had one to begin with — noise that looks like data. This catches the blog
        // index and anything path-nested under it (e.g. /blog/post-slug/); individual
        // articles published at a bare root-level slug (no /blog/ prefix) aren't caught
        // by a URL pattern alone and would need a separate, content-based signal.
        '#/(blog|noutati|stiri|articole|news|press)(/|$)#i',
    ];

    private const TRACKING_PARAMS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid', 'mc_cid', 'mc_eid'];

    private const PRIORITY_KEYWORDS = [
        // Romanian
        'servicii', 'preturi', 'prețuri', 'tarife', 'program', 'contact', 'locatii', 'locații',
        'despre', 'echipa', 'echipă', 'specialisti', 'specialiști', 'intrebari', 'întrebări',
        'faq', 'anulare', 'programari', 'programări',
        // English
        'services', 'pricing', 'prices', 'hours', 'about', 'team', 'staff', 'policies', 'booking',
    ];

    public function __construct(
        private readonly OnboardingSourceFetcher $fetcher,
        private readonly OnboardingPageContentExtractor $extractor,
        private readonly OnboardingUrlNormalizer $urlNormalizer,
    ) {}

    public function crawl(string $entryUrl): CrawlResult
    {
        $normalizedEntry = $this->urlNormalizer->normalize($entryUrl);
        $allowlist = new OnboardingWebsiteHostAllowlist($normalizedEntry);

        $maxPages = (int) config('onboarding.crawl.max_pages', 12);
        $maxDepth = (int) config('onboarding.crawl.max_depth', 2);
        $totalTimeout = (int) config('onboarding.crawl.total_timeout_seconds', 45);
        $maxTotalBytes = (int) config('onboarding.crawl.max_total_download_bytes', 8 * 1024 * 1024);
        $maxTotalChars = (int) config('onboarding.crawl.max_total_extracted_characters', 150000);
        $maxPageBytes = (int) config('onboarding.crawl.max_html_bytes_per_page', 2 * 1024 * 1024);

        $startedAt = microtime(true);
        $visited = [];
        $queued = [$normalizedEntry => true];
        $frontier = [['url' => $normalizedEntry, 'depth' => 0, 'via' => 'homepage', 'score' => PHP_INT_MAX]];

        $pages = [];
        $warnings = [];
        $ignored = [];
        $totalBytes = 0;
        $totalChars = 0;
        $stopReason = 'exhausted_frontier';

        foreach ($this->discoverSitemapUrls($normalizedEntry, $warnings) as $sitemapUrl) {
            $normalized = $this->canonicalize($sitemapUrl);

            if (! isset($queued[$normalized]) && $this->isCandidateAllowed($normalized, $allowlist)) {
                $queued[$normalized] = true;
                $frontier[] = ['url' => $normalized, 'depth' => 1, 'via' => 'sitemap', 'score' => $this->score($normalized, '')];
            }
        }

        while ($frontier !== []) {
            if (microtime(true) - $startedAt > $totalTimeout) {
                $stopReason = 'total_timeout';
                break;
            }

            if (count($pages) >= $maxPages) {
                $stopReason = 'max_pages';
                break;
            }

            usort($frontier, static fn (array $a, array $b) => $b['score'] <=> $a['score']);
            $candidate = array_shift($frontier);

            if (isset($visited[$candidate['url']]) || $candidate['depth'] > $maxDepth) {
                continue;
            }

            $visited[$candidate['url']] = true;

            try {
                $document = $this->fetcher->fetch($candidate['url'], self::HTML_TYPES, $maxPageBytes);
            } catch (FetchException $exception) {
                $warnings[] = "fetch_failed:{$candidate['url']}:{$exception->reasonCode()}";
                $ignored[] = $candidate['url'];

                continue;
            }

            $totalBytes += $document->sizeBytes;

            if ($totalBytes > $maxTotalBytes) {
                $stopReason = 'max_total_download_bytes';
                break;
            }

            $extracted = $this->extractor->extract($document->finalUrl, $document->body);

            // Blog/news articles often don't share a URL prefix with the blog index
            // (WordPress commonly publishes them at a bare root-level slug), so the
            // path-based EXCLUDED_PATH_PATTERNS above can't catch them — this catches
            // them structurally instead, via the near-universal og:type meta tag and/or
            // a breadcrumb trail through a blog-like section, after the page is already
            // fetched. Editorial content mentioning a service/technique in passing (with
            // no price) would otherwise get mistaken by the analyzer for real service data.
            if ($this->looksLikeBlogArticle($extracted)) {
                $ignored[] = $document->finalUrl;

                continue;
            }

            $totalChars += mb_strlen($extracted->mainText);

            $pages[] = new CrawledPage($document->finalUrl, $candidate['depth'], $candidate['via'], $extracted);

            if ($totalChars > $maxTotalChars) {
                $stopReason = 'max_total_extracted_characters';
                break;
            }

            if ($candidate['depth'] < $maxDepth) {
                foreach ($extracted->links as $link) {
                    $normalized = $this->canonicalize($link['url']);

                    if (isset($queued[$normalized]) || isset($visited[$normalized])) {
                        continue;
                    }

                    if (! $this->isCandidateAllowed($normalized, $allowlist)) {
                        $ignored[] = $normalized;

                        continue;
                    }

                    $queued[$normalized] = true;
                    $frontier[] = [
                        'url' => $normalized,
                        'depth' => $candidate['depth'] + 1,
                        'via' => 'link',
                        'score' => $this->score($normalized, $link['text']),
                    ];
                }
            }
        }

        return new CrawlResult($pages, $warnings, $stopReason, array_values(array_unique($ignored)));
    }

    /**
     * @param  list<string>  $warnings
     * @return list<string>
     */
    private function discoverSitemapUrls(string $normalizedEntry, array &$warnings): array
    {
        $parts = parse_url($normalizedEntry);

        if (! isset($parts['scheme'], $parts['host'])) {
            return [];
        }

        $origin = "{$parts['scheme']}://{$parts['host']}".(isset($parts['port']) ? ":{$parts['port']}" : '');
        $maxSitemapBytes = (int) config('onboarding.crawl.max_sitemap_bytes', 512 * 1024);
        $maxSitemapFiles = (int) config('onboarding.crawl.max_sitemap_files', 5);
        $maxSitemapUrls = (int) config('onboarding.crawl.max_sitemap_urls', 200);

        foreach (['/sitemap.xml', '/sitemap_index.xml', '/wp-sitemap.xml'] as $path) {
            try {
                $document = $this->fetcher->fetch($origin.$path, self::SITEMAP_TYPES, $maxSitemapBytes);

                return $this->resolveSitemapUrls($document->body, $maxSitemapFiles, $maxSitemapUrls, $maxSitemapBytes, $warnings);
            } catch (FetchException $exception) {
                $warnings[] = "sitemap_unavailable:{$path}:{$exception->reasonCode()}";
            }
        }

        return [];
    }

    /**
     * A sitemap is either a plain `<urlset>` (page URLs directly) or a `<sitemapindex>`
     * (pointers to *other* sitemap XML files, which must themselves be fetched with the
     * XML content-type allowlist, never treated as HTML pages). Bounded by
     * max_sitemap_files (how many child sitemaps get fetched) and max_sitemap_urls (how
     * many page URLs sitemap discovery may contribute in total).
     *
     * @param  list<string>  $warnings
     * @return list<string>
     */
    private function resolveSitemapUrls(string $xml, int $maxSitemapFiles, int $maxSitemapUrls, int $maxSitemapBytes, array &$warnings): array
    {
        $dom = $this->parseXml($xml);

        if ($dom === null) {
            return [];
        }

        if (strtolower($dom->getName()) !== 'sitemapindex') {
            return array_slice($this->extractLocs($dom), 0, $maxSitemapUrls);
        }

        $childSitemapUrls = array_slice(
            $this->extractNestedLocs($dom, 'sitemap'),
            0,
            $maxSitemapFiles
        );

        $urls = [];

        foreach ($childSitemapUrls as $childUrl) {
            if (count($urls) >= $maxSitemapUrls) {
                break;
            }

            try {
                $childDocument = $this->fetcher->fetch($childUrl, self::SITEMAP_TYPES, $maxSitemapBytes);
                $childDom = $this->parseXml($childDocument->body);

                if ($childDom !== null) {
                    $urls = [...$urls, ...$this->extractLocs($childDom)];
                }
            } catch (FetchException $exception) {
                $warnings[] = "sitemap_child_unavailable:{$childUrl}:{$exception->reasonCode()}";
            }
        }

        return array_slice(array_values(array_unique($urls)), 0, $maxSitemapUrls);
    }

    private function parseXml(string $xml): ?\SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $dom = simplexml_load_string($xml);
        libxml_use_internal_errors($previous);

        return $dom === false ? null : $dom;
    }

    /**
     * @return list<string>
     */
    private function extractLocs(\SimpleXMLElement $dom): array
    {
        $locs = [];

        foreach ($dom->xpath('//*[local-name()="loc"]') ?: [] as $node) {
            $value = trim((string) $node);

            if ($value !== '') {
                $locs[] = $value;
            }
        }

        return $locs;
    }

    /**
     * @return list<string>
     */
    private function extractNestedLocs(\SimpleXMLElement $dom, string $parentElement): array
    {
        $locs = [];

        foreach ($dom->xpath("//*[local-name()=\"{$parentElement}\"]/*[local-name()=\"loc\"]") ?: [] as $node) {
            $value = trim((string) $node);

            if ($value !== '') {
                $locs[] = $value;
            }
        }

        return $locs;
    }

    private function canonicalize(string $url): string
    {
        return $this->urlNormalizer->normalize($this->stripTrackingParams($url));
    }

    private function stripTrackingParams(string $url): string
    {
        $parts = parse_url($url);

        if (! isset($parts['query'])) {
            return $url;
        }

        parse_str($parts['query'], $query);

        foreach (self::TRACKING_PARAMS as $param) {
            unset($query[$param]);
        }

        $rebuilt = $parts['scheme'].'://'.$parts['host']
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($parts['path'] ?? '');

        if ($query !== []) {
            $rebuilt .= '?'.http_build_query($query);
        }

        return $rebuilt;
    }

    private function isCandidateAllowed(string $url, OnboardingWebsiteHostAllowlist $allowlist): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        if (! $allowlist->allows($url)) {
            return false;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $query = (string) parse_url($url, PHP_URL_QUERY);
        $subject = $path.'?'.$query;

        foreach (self::EXCLUDED_PATH_PATTERNS as $pattern) {
            if (preg_match($pattern, $subject)) {
                return false;
            }
        }

        return true;
    }

    /**
     * og:type was tried and dropped: measured against a real site, its theme set
     * og:type="article" on every single page — including /preturi/, /contact/ and
     * location pages, not just actual blog posts — which would have excluded the
     * entire business, not just its blog. JSON-LD BlogPosting/Article schema and an
     * explicit "Blog"/"Noutăți"/etc. breadcrumb segment are the two signals that, on
     * the same real site, correctly flagged only the actual article.
     */
    private function looksLikeBlogArticle(ExtractedPage $extracted): bool
    {
        if ($extracted->hasArticleSchema) {
            return true;
        }

        foreach ($extracted->breadcrumbs as $crumb) {
            if (preg_match('/^(blog|noutati|noutăți|stiri|știri|articole|news|press)$/iu', trim($crumb))) {
                return true;
            }
        }

        return false;
    }

    private function score(string $url, string $anchorText): int
    {
        $haystack = mb_strtolower((parse_url($url, PHP_URL_PATH) ?: '').' '.$anchorText);
        $score = 0;

        foreach (self::PRIORITY_KEYWORDS as $keyword) {
            if (str_contains($haystack, $keyword)) {
                $score += 10;
            }
        }

        $path = parse_url($url, PHP_URL_PATH) ?: '/';

        if ($path === '/' || $path === '') {
            $score += 20;
        }

        return $score;
    }
}
