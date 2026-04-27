<?php

namespace App\Services\Assistant;

use Illuminate\Http\Client\Response;

class AssistantResponseParser
{
    public function parse(Response $response): array
    {
        $parts = $response->json('candidates.0.content.parts', []);

        return [
            'text' => $this->stripMarkdownBold(collect($parts)->pluck('text')->filter()->implode("\n")),
            'function_calls' => collect($parts)
                ->pluck('functionCall')
                ->filter()
                ->values()
                ->all(),
        ];
    }

    public function finalText(?string $text): string
    {
        return $this->stripMarkdownBold($text ?: 'Nu am inteles exact. Poti reformula?');
    }

    public function stripMarkdownBold(string $text): string
    {
        return str_replace('**', '', $text);
    }
}
