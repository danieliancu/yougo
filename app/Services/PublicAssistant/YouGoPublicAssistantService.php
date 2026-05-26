<?php

namespace App\Services\PublicAssistant;

use Illuminate\Support\Facades\Http;
use Throwable;

class YouGoPublicAssistantService
{
    public function __construct(private readonly YouGoPublicAssistantKnowledge $knowledge)
    {
    }

    public function reply(array $messages, string $locale = 'ro', array $context = []): string
    {
        if (! config('services.gemini.key')) {
            return $this->fallback($locale);
        }

        try {
            $response = Http::withOptions([
                    'proxy' => '',
                    'verify' => config('services.gemini.ca_bundle'),
                ])
                ->timeout(30)
            ->post($this->endpoint(), $this->payload($messages, $locale, $context));

            if (! $response->successful()) {
                return $this->fallback($locale);
            }

            return $this->extractText($response->json()) ?: $this->fallback($locale);
        } catch (Throwable) {
            return $this->fallback($locale);
        }
    }

    private function endpoint(): string
    {
        $model = config('services.gemini.model', 'gemini-3-flash-preview');

        return "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=".config('services.gemini.key');
    }

    private function payload(array $messages, string $locale, array $context): array
    {
        return [
            'systemInstruction' => [
                'parts' => [[
                    'text' => $this->knowledge->systemPrompt($locale)."\n\nSafe current context: ".json_encode($this->safeContext($context), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]],
            ],
            'contents' => collect($messages)->take(-15)->map(fn (array $message) => [
                'role' => $message['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $message['content']]],
            ])->values()->all(),
        ];
    }

    private function safeContext(array $context): array
    {
        return collect($context)
            ->only(['surface', 'current_path', 'current_section', 'authenticated', 'plan_key', 'business_name'])
            ->all();
    }

    private function extractText(array $body): string
    {
        $parts = $body['candidates'][0]['content']['parts'] ?? [];

        return trim(collect($parts)->pluck('text')->filter()->implode("\n"));
    }

    private function fallback(string $locale): string
    {
        if ($locale === 'en') {
            return 'I cannot answer right now. You can still ask about plans, setup, widget installation or contact YouGo for help.';
        }

        return 'Nu pot raspunde chiar acum. Poti intreba in continuare despre planuri, configurare, instalarea widgetului sau poti contacta echipa YouGo.';
    }
}
