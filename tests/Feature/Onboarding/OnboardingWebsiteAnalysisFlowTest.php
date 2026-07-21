<?php

namespace Tests\Feature\Onboarding;

use App\Models\Salon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Full pipeline over HTTP using the real production driver (gemini_website): start ->
 * job -> fetch (faked, IP-literal host so no real DNS is needed) -> AI (faked) ->
 * central validation -> deduplication -> review_required. Task 1's job-level staleness/
 * claim-token/supersession mechanics are analyzer-agnostic and already covered by
 * OnboardingImportJobTest; this test is specifically about the real analyzer wiring.
 */
class OnboardingWebsiteAnalysisFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'queue.default' => 'sync',
            'onboarding.analyzer.driver' => 'gemini_website',
            'services.gemini.key' => 'fake-key',
        ]);
    }

    public function test_full_flow_with_the_real_analyzer_reaches_review_required(): void
    {
        $siteHtml = <<<'HTML'
        <html><head><title>Salon Frumusete</title>
        <script type="application/ld+json">
        {"@type":"LocalBusiness","name":"Salon Frumusete","telephone":"+40745123456","address":{"streetAddress":"Str. Exemplu 1","addressLocality":"Bucuresti"}}
        </script>
        </head><body>Servicii: Manichiura 100 lei. Program: Luni 09:00 - 18:00.</body></html>
        HTML;

        Http::fake([
            'http://93.184.216.34*' => Http::response($siteHtml, 200, ['Content-Type' => 'text/html']),
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'services' => [[
                    'name' => ['value' => 'Manichiura', 'source_url' => 'http://93.184.216.34'],
                    'category' => ['value' => 'unghii', 'source_url' => 'http://93.184.216.34'],
                    'price' => ['value' => ['type' => 'fixed', 'amount' => 100, 'currency' => 'RON'], 'source_url' => 'http://93.184.216.34'],
                ]],
            ])),
        ]);

        [, $user] = $this->createSalonAndUser();

        $response = $this->actingAs($user)->postJson('/onboarding/import', [
            'source_type' => 'url',
            'source_url' => 'http://93.184.216.34/',
        ]);

        $response->assertOk();
        $this->assertSame('review_required', $response->json('status'));
        $this->assertNotNull($response->json('normalized_extraction_result.business.name'));
    }

    public function test_raw_content_never_appears_in_the_api_response(): void
    {
        $secretMarker = 'INTERNAL-MARKER-'.uniqid();
        $siteHtml = "<html><body>{$secretMarker} Manichiura 100 lei</body></html>";

        Http::fake([
            'http://93.184.216.34*' => Http::response($siteHtml, 200, ['Content-Type' => 'text/html']),
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse(['services' => []])),
        ]);

        [, $user] = $this->createSalonAndUser();

        $response = $this->actingAs($user)->postJson('/onboarding/import', [
            'source_type' => 'url',
            'source_url' => 'http://93.184.216.34/',
        ]);

        $response->assertOk();
        $this->assertStringNotContainsString($secretMarker, $response->content());
        $this->assertArrayNotHasKey('raw_extraction_result', $response->json());
    }

    /**
     * @return array{0: Salon, 1: User}
     */
    private function createSalonAndUser(): array
    {
        $user = User::factory()->create();
        /** @var Salon $salon */
        $salon = $user->salon()->create(['name' => '']);

        return [$salon, $user];
    }

    private function geminiResponse(array $json): array
    {
        return [
            'candidates' => [
                ['content' => ['parts' => [['text' => json_encode($json)]]], 'finishReason' => 'STOP'],
            ],
        ];
    }
}
