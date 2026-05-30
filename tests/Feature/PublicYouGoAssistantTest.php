<?php

namespace Tests\Feature;

use App\Services\PublicAssistant\YouGoPublicAssistantKnowledge;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PublicYouGoAssistantTest extends TestCase
{
    public function test_public_assistant_endpoint_validates_request(): void
    {
        $this->postJson('/yougo-assistant/chat', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['messages']);
    }

    public function test_public_assistant_endpoint_rejects_empty_message(): void
    {
        $this->postJson('/yougo-assistant/chat', [
            'messages' => [
                ['role' => 'user', 'content' => '   '],
            ],
        ])->assertUnprocessable();
    }

    public function test_public_assistant_endpoint_uses_public_prompt_and_returns_message(): void
    {
        config(['services.gemini.key' => 'test-key']);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [
                            ['text' => 'YouGo helps you answer clients and collect booking requests.'],
                        ],
                    ],
                ]],
            ]),
        ]);

        $this->postJson('/yougo-assistant/chat', [
            'locale' => 'en',
            'messages' => [
                ['role' => 'user', 'content' => 'What is YouGo?'],
            ],
        ])
            ->assertOk()
            ->assertJson(['message' => 'YouGo helps you answer clients and collect booking requests.']);

        Http::assertSent(function ($request) {
            $prompt = $request->data()['systemInstruction']['parts'][0]['text'] ?? '';

            return str_contains($prompt, 'public YouGo website support assistant')
                && str_contains($prompt, 'website_chat: live')
                && str_contains($prompt, 'whatsapp_ai: live')
                && str_contains($prompt, 'phone_ai: planned')
                && str_contains($prompt, 'WhatsApp AI is available as an MVP')
                && ! str_contains($prompt, 'asistentul virtual pentru {$salon->name}');
        });
    }

    public function test_dashboard_booking_question_returns_text_only_response(): void
    {
        config(['services.gemini.key' => null]);

        $this->postJson('/yougo-assistant/chat', [
            'locale' => 'ro',
            'context' => [
                'surface' => 'dashboard',
                'authenticated' => true,
                'current_section' => 'overview',
                'plan_key' => 'website_chat',
            ],
            'messages' => [
                ['role' => 'user', 'content' => 'Unde vad programarile?'],
            ],
        ])
            ->assertOk()
            ->assertJsonMissingPath('actions');
    }

    public function test_widget_question_does_not_return_navigation_actions(): void
    {
        config(['services.gemini.key' => null]);

        $this->postJson('/yougo-assistant/chat', [
            'locale' => 'ro',
            'context' => ['surface' => 'dashboard', 'authenticated' => true],
            'messages' => [
                ['role' => 'user', 'content' => 'Unde pun codul de instalare widget?'],
            ],
        ])
            ->assertOk()
            ->assertJsonMissingPath('actions');
    }

    public function test_assistant_response_mentions_dashboard_sections_without_navigation_actions(): void
    {
        config(['services.gemini.key' => 'test-key']);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => 'Sectiunea Facturare se afla la adresa /dashboard/billing. Sectiunea Dashboard Overview se afla la adresa /dashboard.',
                        ]],
                    ],
                ]],
            ]),
        ]);

        $this->postJson('/yougo-assistant/chat', [
            'locale' => 'ro',
            'context' => ['surface' => 'dashboard', 'authenticated' => true],
            'messages' => [
                ['role' => 'user', 'content' => 'Unde gasesc sectiunile astea?'],
            ],
        ])
            ->assertOk()
            ->assertJsonMissingPath('actions');
    }

    public function test_public_dashboard_question_returns_text_only_response(): void
    {
        config(['services.gemini.key' => null]);

        $this->postJson('/yougo-assistant/chat', [
            'locale' => 'en',
            'context' => ['surface' => 'public', 'authenticated' => false],
            'messages' => [
                ['role' => 'user', 'content' => 'Where can I see my dashboard bookings?'],
            ],
        ])
            ->assertOk()
            ->assertJsonMissingPath('actions');
    }

    public function test_public_assistant_route_is_throttled(): void
    {
        $route = Route::getRoutes()->getByName('yougo-assistant.chat');

        $this->assertNotNull($route);
        $this->assertContains('throttle:20,1', $route->gatherMiddleware());
    }

    public function test_public_assistant_knowledge_includes_current_plans_and_services(): void
    {
        $prompt = app(YouGoPublicAssistantKnowledge::class)->systemPrompt('en');

        foreach (['Free', 'Website Chat', 'Chat + WhatsApp', 'YouGo Starter', 'YouGo Growth', 'YouGo Pro'] as $plan) {
            $this->assertStringContainsString($plan, $prompt);
        }

        $this->assertStringContainsString('website_chat: live', $prompt);
        $this->assertStringContainsString('whatsapp_ai: live', $prompt);
        $this->assertStringContainsString('phone_ai: planned', $prompt);
        $this->assertStringContainsString('WhatsApp AI is available as an MVP', $prompt);
        $this->assertStringContainsString('requires manual activation through YouGo/Twilio', $prompt);
        $this->assertStringContainsString('Known help routes and navigation targets', $prompt);
        $this->assertStringContainsString('dashboard.bookings', $prompt);
        $this->assertStringContainsString('docs/features/README.md', $prompt);
        $this->assertStringContainsString('Do not create bookings for any salon', $prompt);
        $this->assertStringContainsString('Return plain text only. Do not use Markdown.', $prompt);
    }

    public function test_public_chat_frontend_is_text_only_and_mounted_on_public_pages(): void
    {
        $component = file_get_contents(resource_path('js/Components/YouGoCopilot.tsx'));
        $landing = file_get_contents(resource_path('js/Pages/Landing.tsx'));
        $industry = file_get_contents(resource_path('js/Pages/Industries/Show.tsx'));
        $dashboard = file_get_contents(resource_path('js/Pages/Dashboard/Index.tsx'));

        $this->assertStringContainsString('publicChatOpen', $component);
        $this->assertStringContainsString('publicChatInitialMessage', $component);
        $this->assertStringContainsString('publicChatQuickFree', $component);
        $this->assertStringContainsString('/yougo-assistant/chat', $component);
        $this->assertStringNotContainsString('router.visit', $component);
        $this->assertStringNotContainsString('actions', $component);
        $this->assertStringContainsString('/images/icon.png', $component);
        $this->assertStringContainsString('cleanAssistantText', $component);
        $this->assertStringNotContainsString('microphone', strtolower($component));
        $this->assertStringNotContainsString('MediaRecorder', $component);
        $this->assertStringContainsString('PublicYouGoChat', $landing);
        $this->assertStringContainsString('PublicYouGoChat', $industry);
        $this->assertStringContainsString('YouGoCopilot', $dashboard);
    }
}
