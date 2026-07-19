<?php

namespace App\Services\Onboarding\Crawler;

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
    ];

    private const TRACKING_PARAMS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid', 'mc_cid', 'mc_eid'];

    private const PRIORITY_KEYWORDS = [
        // Romanian
        'servicii', 'preturi', 'prețuri', 'tarife', 'program', 'contact', 'locatii', 'locații',
        'despre', 'echipa', 'echipă', 'specialisti', 'specialiști', 'intrebari', 'întrebări',
        'faq', 'termeni', 'anulare', 'programari', 'programări',
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

        foreach (['/sitemap.xml', '/sitemap_index.xml'] as $path) {
            try {
                $document = $this->fetcher->fetch($origin.$path, self::SITEMAP_TYPES, $maxSitemapBytes);

                return $this->parseSitemapLocs($document->body);
            } catch (FetchException $exception) {
                $warnings[] = "sitemap_unavailable:{$path}:{$exception->reasonCode()}";
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function parseSitemapLocs(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        $dom = simplexml_load_string($xml);
        libxml_use_internal_errors($previous);

        if ($dom === false) {
            return [];
        }

        $locs = [];

        foreach ($dom->xpath('//*[local-name()="loc"]') ?: [] as $node) {
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
