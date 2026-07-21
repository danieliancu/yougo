<?php

namespace App\Services\Onboarding\Extraction;

use DOMDocument;
use DOMXPath;

/**
 * Deterministic HTML -> structured content extraction, using PHP's built-in
 * DOMDocument/DOMXPath (ext-dom, no new composer dependency). Strips script/style/
 * noscript before extracting text; JSON-LD is parsed and returned separately so the
 * analyzer can treat it as a distinct, higher-trust-but-still-verified signal (never
 * blindly trusted — cross-checked against visible text by ImportedFactMerger).
 *
 * Cookie-banner/nav/footer stripping is best-effort (tag/class heuristics), not
 * guaranteed — documented limitation, not a correctness claim.
 */
class OnboardingPageContentExtractor
{
    private const SOCIAL_DOMAINS = ['facebook.com', 'instagram.com', 'tiktok.com', 'linkedin.com', 'wa.me'];

    private const JSON_LD_TYPES = [
        'LocalBusiness', 'Organization', 'PostalAddress', 'OpeningHoursSpecification',
        'Service', 'Offer', 'Person', 'FAQPage',
    ];

    private const NOISE_SELECTORS_CLASS_HINTS = ['cookie', 'consent', 'gdpr', 'newsletter-popup'];

    // Deliberately just BlogPosting, not the more generic "Article"/"NewsArticle":
    // measured against a real site, Yoast's shared @graph attaches a generic "Article"
    // node on every page (not just posts) as part of its WebPage/mainEntity linking —
    // BlogPosting was, on the same site, present only on the actual blog post.
    private const ARTICLE_JSON_LD_TYPES = ['BlogPosting'];

    public function extract(string $url, string $html): ExtractedPage
    {
        $dom = $this->loadHtml($html);
        $xpath = new DOMXPath($dom);

        // Extracted before stripNoise() removes <script> tags — JSON-LD lives inside
        // <script type="application/ld+json">, which noise-stripping would otherwise
        // delete before it's ever read.
        $jsonLd = $this->jsonLd($xpath);
        $hasArticleSchema = $this->hasArticleSchema($xpath) || $this->isWordPressBlogPost($xpath);

        $this->stripNoise($dom, $xpath);

        return new ExtractedPage(
            url: $url,
            title: $this->title($xpath),
            metaDescription: $this->metaDescription($xpath),
            hasArticleSchema: $hasArticleSchema,
            headings: $this->headings($xpath),
            mainText: $this->mainText($dom),
            lists: $this->lists($xpath),
            tables: $this->tables($xpath),
            phones: $this->phones($xpath, $dom),
            emails: $this->emails($xpath, $dom),
            socialLinks: $this->socialLinks($xpath),
            jsonLd: $jsonLd,
            breadcrumbs: $this->breadcrumbs($xpath),
            links: $this->links($xpath, $url),
        );
    }

    private function loadHtml(string $html): DOMDocument
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        // Forces DOMDocument to treat the content as UTF-8 without mangling accented
        // characters/diacritics — the standard workaround for DOMDocument's default
        // (non-UTF-8) HTML parsing behavior.
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $dom;
    }

    private function stripNoise(DOMDocument $dom, DOMXPath $xpath): void
    {
        foreach (iterator_to_array($xpath->query('//script | //style | //noscript')) as $node) {
            $node->parentNode?->removeChild($node);
        }

        foreach (self::NOISE_SELECTORS_CLASS_HINTS as $hint) {
            foreach (iterator_to_array($xpath->query("//*[contains(translate(@class, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), '{$hint}')]")) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }
    }

    private function title(DOMXPath $xpath): ?string
    {
        $node = $xpath->query('//title')->item(0);
        $text = $node ? $this->collapse($node->textContent) : null;

        return $text !== '' ? $text : null;
    }

    private function metaDescription(DOMXPath $xpath): ?string
    {
        $node = $xpath->query('//meta[@name="description"]/@content')->item(0);
        $text = $node ? $this->collapse($node->nodeValue) : null;

        return $text !== '' ? $text : null;
    }

    /**
     * Whether the page declares itself as a blog post via JSON-LD BlogPosting (see
     * ARTICLE_JSON_LD_TYPES for why not the more generic "Article" too) — used by the
     * crawler to skip blog articles, whose casual mentions of a technique/service (with
     * no price) otherwise get mistaken for real service data. Deliberately not using
     * the og:type meta tag for this: measured against a real site, its theme set
     * og:type="article" on every single page (including /preturi/ and location pages),
     * which would have excluded the whole business.
     */
    private function hasArticleSchema(DOMXPath $xpath): bool
    {
        foreach ($xpath->query('//script[@type="application/ld+json"]') as $node) {
            $decoded = json_decode($node->textContent, true);

            if (! is_array($decoded)) {
                continue;
            }

            foreach ($this->flattenJsonLd($decoded) as $entry) {
                $type = $entry['@type'] ?? null;
                $types = is_array($type) ? $type : [$type];

                if (array_intersect($types, self::ARTICLE_JSON_LD_TYPES) !== []) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * WordPress core's body_class() adds "single-post" only on a singular view of the
     * built-in "post" type (i.e. an actual blog article) — never on pages, custom post
     * types, or archive/listing views. Unlike og:type or JSON-LD @type (both of which,
     * on real sites, get stamped "article" by the theme/SEO-plugin on every single page,
     * see hasArticleSchema()), this class is emitted by WordPress core itself, not by a
     * theme or plugin, so it generalizes across WordPress sites regardless of theme —
     * caught a real blog article on a site whose theme/plugin emitted neither JSON-LD
     * BlogPosting nor a breadcrumb trail.
     */
    private function isWordPressBlogPost(DOMXPath $xpath): bool
    {
        $body = $xpath->query('//body')->item(0);

        if ($body === null) {
            return false;
        }

        $classes = preg_split('/\s+/', trim($body->getAttribute('class')));

        return in_array('single-post', $classes, true);
    }

    /**
     * @return list<string>
     */
    private function headings(DOMXPath $xpath): array
    {
        $headings = [];

        foreach ($xpath->query('//h1 | //h2 | //h3 | //h4 | //h5 | //h6') as $node) {
            $text = $this->collapse($node->textContent);

            if ($text !== '') {
                $headings[] = $text;
            }
        }

        return $headings;
    }

    private function mainText(DOMDocument $dom): string
    {
        $body = $dom->getElementsByTagName('body')->item(0);
        $text = $body ? $this->collapse($body->textContent) : '';

        $maxChars = (int) config('onboarding.crawl.max_extracted_characters_per_page', 30000);

        return mb_substr($text, 0, $maxChars);
    }

    /**
     * @return list<list<string>>
     */
    private function lists(DOMXPath $xpath): array
    {
        $lists = [];

        foreach ($xpath->query('//ul | //ol') as $listNode) {
            $items = [];

            foreach ($xpath->query('./li', $listNode) as $itemNode) {
                $text = $this->collapse($itemNode->textContent);

                if ($text !== '') {
                    $items[] = $text;
                }
            }

            if ($items !== []) {
                $lists[] = $items;
            }
        }

        return $lists;
    }

    /**
     * @return list<list<list<string>>>
     */
    private function tables(DOMXPath $xpath): array
    {
        $tables = [];

        foreach ($xpath->query('//table') as $tableNode) {
            $rows = [];

            foreach ($xpath->query('.//tr', $tableNode) as $rowNode) {
                $cells = [];

                foreach ($xpath->query('./td | ./th', $rowNode) as $cellNode) {
                    $cells[] = $this->collapse($cellNode->textContent);
                }

                if ($cells !== []) {
                    $rows[] = $cells;
                }
            }

            if ($rows !== []) {
                $tables[] = $rows;
            }
        }

        return $tables;
    }

    /**
     * @return list<string>
     */
    private function phones(DOMXPath $xpath, DOMDocument $dom): array
    {
        $phones = [];

        foreach ($xpath->query('//a[starts-with(@href, "tel:")]/@href') as $node) {
            $phones[] = $this->normalizePhone(substr($node->nodeValue, 4));
        }

        $text = $dom->getElementsByTagName('body')->item(0)?->textContent ?? '';

        if (preg_match_all('/(\+?4?0)\s?7\d{2}[\s.\-]?\d{3}[\s.\-]?\d{3}/', $text, $matches)) {
            foreach ($matches[0] as $match) {
                $phones[] = $this->normalizePhone($match);
            }
        }

        return array_values(array_unique(array_filter($phones)));
    }

    /**
     * @return list<string>
     */
    private function emails(DOMXPath $xpath, DOMDocument $dom): array
    {
        $emails = [];

        foreach ($xpath->query('//a[starts-with(@href, "mailto:")]/@href') as $node) {
            $emails[] = strtolower(trim(explode('?', substr($node->nodeValue, 7))[0]));
        }

        $text = $dom->getElementsByTagName('body')->item(0)?->textContent ?? '';

        if (preg_match_all('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $text, $matches)) {
            foreach ($matches[0] as $match) {
                $emails[] = strtolower($match);
            }
        }

        return array_values(array_unique(array_filter($emails)));
    }

    /**
     * @return list<string>
     */
    private function socialLinks(DOMXPath $xpath): array
    {
        $links = [];

        foreach ($xpath->query('//a[@href]') as $node) {
            $href = $node->getAttribute('href');

            foreach (self::SOCIAL_DOMAINS as $domain) {
                if (str_contains($href, $domain)) {
                    $links[] = $href;

                    break;
                }
            }
        }

        return array_values(array_unique($links));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function jsonLd(DOMXPath $xpath): array
    {
        $blocks = [];

        foreach ($xpath->query('//script[@type="application/ld+json"]') as $node) {
            $decoded = json_decode($node->textContent, true);

            if (! is_array($decoded)) {
                continue;
            }

            foreach ($this->flattenJsonLd($decoded) as $entry) {
                $type = $entry['@type'] ?? null;
                $types = is_array($type) ? $type : [$type];

                if (array_intersect($types, self::JSON_LD_TYPES) !== []) {
                    $blocks[] = $entry;
                }
            }
        }

        return $blocks;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function flattenJsonLd(array $decoded): array
    {
        if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
            return array_values(array_filter($decoded['@graph'], 'is_array'));
        }

        if (array_is_list($decoded)) {
            return array_values(array_filter($decoded, 'is_array'));
        }

        return [$decoded];
    }

    /**
     * @return list<string>
     */
    private function breadcrumbs(DOMXPath $xpath): array
    {
        $items = [];

        $nodes = $xpath->query('//nav[contains(translate(@aria-label, "BREADCRUM", "breadcrum"), "breadcrumb")]//li | //*[@itemtype and contains(@itemtype, "BreadcrumbList")]//li');

        foreach ($nodes as $node) {
            $text = $this->collapse($node->textContent);

            if ($text !== '') {
                $items[] = $text;
            }
        }

        return $items;
    }

    /**
     * @return list<array{url: string, text: string}>
     */
    private function links(DOMXPath $xpath, string $baseUrl): array
    {
        $links = [];

        foreach ($xpath->query('//a[@href]') as $node) {
            $href = trim($node->getAttribute('href'));

            if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'javascript:') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
                continue;
            }

            $absolute = $this->resolveAbsolute($baseUrl, $href);

            if ($absolute === null) {
                continue;
            }

            $links[] = ['url' => $absolute, 'text' => $this->collapse($node->textContent)];
        }

        return $links;
    }

    private function resolveAbsolute(string $base, string $href): ?string
    {
        if (parse_url($href, PHP_URL_SCHEME) !== null) {
            return in_array(parse_url($href, PHP_URL_SCHEME), ['http', 'https'], true) ? $href : null;
        }

        $baseParts = parse_url($base);

        if (! isset($baseParts['scheme'], $baseParts['host'])) {
            return null;
        }

        $scheme = $baseParts['scheme'];
        $host = $baseParts['host'];
        $port = isset($baseParts['port']) ? ':'.$baseParts['port'] : '';

        if (str_starts_with($href, '//')) {
            return "{$scheme}:{$href}";
        }

        if (str_starts_with($href, '/')) {
            return "{$scheme}://{$host}{$port}{$href}";
        }

        $basePath = $baseParts['path'] ?? '/';

        return "{$scheme}://{$host}{$port}{$this->directoryOf($basePath)}/{$href}";
    }

    /**
     * PHP's dirname() is filesystem-oriented and, on Windows, can mangle URL paths
     * (mixing in backslashes) — this is a plain string operation on the URL path
     * instead, portable across platforms.
     */
    private function directoryOf(string $path): string
    {
        $lastSlash = strrpos($path, '/');

        return $lastSlash === false ? '' : substr($path, 0, $lastSlash);
    }

    private function normalizePhone(string $value): string
    {
        $value = preg_replace('/[^0-9+]/', '', $value) ?? '';

        if (str_starts_with($value, '0') && strlen($value) === 10) {
            return '+4'.$value;
        }

        return $value;
    }

    private function collapse(?string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');
    }
}
