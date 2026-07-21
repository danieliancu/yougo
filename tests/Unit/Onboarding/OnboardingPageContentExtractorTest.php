<?php

namespace Tests\Unit\Onboarding;

use App\Services\Onboarding\Extraction\OnboardingPageContentExtractor;
use Tests\TestCase;

class OnboardingPageContentExtractorTest extends TestCase
{
    public function test_extracts_romanian_business_with_diacritics_name_phone_and_address(): void
    {
        $html = <<<'HTML'
        <html><head><title>Salon Frumusețe Rădăuți</title>
        <meta name="description" content="Salon de înfrumusețare în centrul orașului"></head>
        <body>
        <h1>Salon Frumusețe Rădăuți</h1>
        <p>Ne găsiți la Str. Ștefan cel Mare nr. 10, Rădăuți.</p>
        <p>Telefon: 0745 123 456</p>
        <a href="mailto:contact@salon.ro">Scrie-ne</a>
        </body></html>
        HTML;

        $extractor = new OnboardingPageContentExtractor;
        $page = $extractor->extract('https://salon.ro/', $html);

        $this->assertSame('Salon Frumusețe Rădăuți', $page->title);
        $this->assertStringContainsString('Ștefan cel Mare', $page->mainText);
        $this->assertContains('+40745123456', $page->phones);
        $this->assertContains('contact@salon.ro', $page->emails);
    }

    public function test_extracts_opening_hours_table_and_services_list(): void
    {
        $html = <<<'HTML'
        <html><body>
        <h2>Program</h2>
        <table><tr><td>Luni</td><td>09:00 - 18:00</td></tr></table>
        <h2>Servicii</h2>
        <ul><li>Manichiura - 100 lei</li><li>Pedichiura - 120 lei</li></ul>
        </body></html>
        HTML;

        $extractor = new OnboardingPageContentExtractor;
        $page = $extractor->extract('https://salon.ro/servicii', $html);

        $this->assertSame([[['Luni', '09:00 - 18:00']]], $page->tables);
        $this->assertSame([['Manichiura - 100 lei', 'Pedichiura - 120 lei']], $page->lists);
    }

    public function test_extracts_json_ld_local_business(): void
    {
        $html = <<<'HTML'
        <html><head>
        <script type="application/ld+json">
        {"@context":"https://schema.org","@type":"LocalBusiness","name":"Salon X","telephone":"+40745123456"}
        </script>
        </head><body></body></html>
        HTML;

        $extractor = new OnboardingPageContentExtractor;
        $page = $extractor->extract('https://salon.ro/', $html);

        $this->assertCount(1, $page->jsonLd);
        $this->assertSame('Salon X', $page->jsonLd[0]['name']);
    }

    public function test_extracts_faq_json_ld_page(): void
    {
        $html = <<<'HTML'
        <html><head>
        <script type="application/ld+json">
        {"@type":"FAQPage","mainEntity":[{"@type":"Question","name":"Program?","acceptedAnswer":{"@type":"Answer","text":"Luni-Vineri"}}]}
        </script>
        </head><body></body></html>
        HTML;

        $extractor = new OnboardingPageContentExtractor;
        $page = $extractor->extract('https://salon.ro/faq', $html);

        $this->assertCount(1, $page->jsonLd);
        $this->assertSame('FAQPage', $page->jsonLd[0]['@type']);
    }

    public function test_ignores_invalid_json_ld_without_failing(): void
    {
        $html = '<html><head><script type="application/ld+json">{not valid json</script></head><body>hi</body></html>';

        $extractor = new OnboardingPageContentExtractor;
        $page = $extractor->extract('https://salon.ro/', $html);

        $this->assertSame([], $page->jsonLd);
        $this->assertStringContainsString('hi', $page->mainText);
    }

    public function test_extracts_social_links(): void
    {
        $html = '<html><body><a href="https://www.facebook.com/salonx">FB</a><a href="https://instagram.com/salonx">IG</a><a href="https://example.com">Other</a></body></html>';

        $extractor = new OnboardingPageContentExtractor;
        $page = $extractor->extract('https://salon.ro/', $html);

        $this->assertCount(2, $page->socialLinks);
    }

    public function test_resolves_relative_links_to_absolute(): void
    {
        $html = '<html><body><a href="/servicii">Servicii</a><a href="contact.html">Contact</a></body></html>';

        $extractor = new OnboardingPageContentExtractor;
        $page = $extractor->extract('https://salon.ro/despre', $html);

        $urls = array_column($page->links, 'url');
        $this->assertContains('https://salon.ro/servicii', $urls);
        $this->assertContains('https://salon.ro/contact.html', $urls);
    }

    public function test_strips_script_and_style_from_main_text(): void
    {
        $html = '<html><body><script>alert("x")</script><style>.a{color:red}</style><p>Continut vizibil</p></body></html>';

        $extractor = new OnboardingPageContentExtractor;
        $page = $extractor->extract('https://salon.ro/', $html);

        $this->assertStringNotContainsString('alert', $page->mainText);
        $this->assertStringNotContainsString('color:red', $page->mainText);
        $this->assertStringContainsString('Continut vizibil', $page->mainText);
    }

    public function test_js_only_page_yields_near_empty_extraction(): void
    {
        $html = '<html><body><div id="app"></div><script src="/app.js"></script></body></html>';

        $extractor = new OnboardingPageContentExtractor;
        $page = $extractor->extract('https://spa.ro/', $html);

        $this->assertSame('', $page->mainText);
        $this->assertSame([], $page->jsonLd);
    }

    public function test_truncates_main_text_to_configured_limit(): void
    {
        config(['onboarding.crawl.max_extracted_characters_per_page' => 50]);
        $html = '<html><body><p>'.str_repeat('a', 200).'</p></body></html>';

        $extractor = new OnboardingPageContentExtractor;
        $page = $extractor->extract('https://salon.ro/', $html);

        $this->assertSame(50, mb_strlen($page->mainText));
    }
}
