<?php

namespace App\Services\ServiceImport;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ServiceImageImportService
{
    public const MAX_SERVICES = 50;

    public function analyze(UploadedFile $image): array
    {
        if (! config('services.gemini.key')) {
            throw new RuntimeException('AI service is not configured.');
        }

        $response = Http::withOptions([
                'proxy' => '',
                'verify' => config('services.gemini.ca_bundle'),
            ])
            ->connectTimeout(10)
            ->timeout((int) config('services.gemini.vision_timeout', 60))
            ->post($this->endpoint(), $this->payload($image));

        if (! $response->successful()) {
            $message = $response->json('error.message') ?: 'AI image analysis failed.';

            throw new RuntimeException('AI image analysis failed: '.$message);
        }

        $decoded = $this->decodeJson($this->extractText($response->json()));

        return [
            'services' => $this->normalizeServices($this->serviceRowsFromDecoded($decoded))->all(),
            'warning' => $decoded['warning'] ?? null,
        ];
    }

    public function normalizeServices(array $services): Collection
    {
        $seen = [];

        return collect($services)
            ->filter(fn ($service) => is_array($service))
            ->map(fn (array $service) => $this->normalizeService($service))
            ->filter(fn (?array $service) => $service !== null)
            ->filter(function (array $service) use (&$seen) {
                $key = $this->normalizeName($service['name']);
                if ($key === '' || isset($seen[$key])) {
                    return false;
                }

                $seen[$key] = true;

                return true;
            })
            ->take(self::MAX_SERVICES)
            ->values();
    }

    private function endpoint(): string
    {
        $model = config('services.gemini.vision_model', config('services.gemini.model', 'gemini-3-flash-preview'));

        return "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=".config('services.gemini.key');
    }

    private function payload(UploadedFile $image): array
    {
        return [
            'systemInstruction' => [
                'parts' => [[
                    'text' => 'You are extracting business services from a price list image. Return only valid JSON, no markdown, no explanation. Extract every visible service row. Use the visible card/section heading as the category for each service. Do not invent services or categories. If something is unclear, use null.',
                ]],
            ],
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    [
                        'text' => 'Return a flat array inside this exact object shape: {"services":[{"name":"1 zona","category":"ANTI-WRINKLE INJECTIONS (BOTOX)","duration_minutes":null,"price":170,"description":null,"notes":null}],"warning":null}. Category must be copied from the nearest visible heading. Prices must be numbers only. Do not group by category.',
                    ],
                    [
                        'inlineData' => [
                            'mimeType' => $image->getMimeType() ?: 'image/jpeg',
                            'data' => base64_encode(file_get_contents($image->getRealPath())),
                        ],
                    ],
                ],
            ]],
            'generationConfig' => [
                'temperature' => 0.1,
                'maxOutputTokens' => 4096,
                'responseMimeType' => 'application/json',
                'responseSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'services' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'name' => ['type' => 'string'],
                                    'category' => ['type' => 'string', 'nullable' => true],
                                    'duration_minutes' => ['type' => 'integer', 'nullable' => true],
                                    'price' => ['type' => 'number', 'nullable' => true],
                                    'description' => ['type' => 'string', 'nullable' => true],
                                    'notes' => ['type' => 'string', 'nullable' => true],
                                ],
                                'required' => ['name', 'category', 'duration_minutes', 'price', 'description', 'notes'],
                            ],
                        ],
                        'warning' => ['type' => 'string', 'nullable' => true],
                    ],
                    'required' => ['services', 'warning'],
                ],
            ],
        ];
    }

    private function serviceRowsFromDecoded(array $decoded): array
    {
        if (isset($decoded['services']) && is_array($decoded['services'])) {
            return $decoded['services'];
        }

        if (isset($decoded['categories']) && is_array($decoded['categories'])) {
            return $this->flattenCategoryGroups($decoded['categories']);
        }

        if (isset($decoded['service_categories']) && is_array($decoded['service_categories'])) {
            return $this->flattenCategoryGroups($decoded['service_categories']);
        }

        if (array_is_list($decoded)) {
            return $this->looksLikeCategoryGroups($decoded)
                ? $this->flattenCategoryGroups($decoded)
                : $decoded;
        }

        $rows = [];
        foreach ($decoded as $category => $items) {
            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $rows[] = [
                    ...$item,
                    'category' => $item['category'] ?? (string) $category,
                ];
            }
        }

        return $rows;
    }

    private function flattenCategoryGroups(array $groups): array
    {
        $rows = [];

        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            $category = $group['category'] ?? $group['name'] ?? $group['title'] ?? $group['heading'] ?? null;
            $services = $group['services'] ?? $group['items'] ?? $group['treatments'] ?? [];

            if (! is_array($services)) {
                continue;
            }

            foreach ($services as $service) {
                if (! is_array($service)) {
                    continue;
                }

                $rows[] = [
                    ...$service,
                    'category' => $service['category'] ?? $category,
                ];
            }
        }

        return $rows;
    }

    private function looksLikeCategoryGroups(array $items): bool
    {
        foreach ($items as $item) {
            if (is_array($item) && (isset($item['services']) || isset($item['items']) || isset($item['treatments']))) {
                return true;
            }
        }

        return false;
    }

    private function extractText(array $body): string
    {
        $parts = $body['candidates'][0]['content']['parts'] ?? [];

        return trim(collect($parts)->pluck('text')->filter()->implode("\n"));
    }

    private function decodeJson(string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            return ['services' => [], 'warning' => 'empty_response'];
        }

        $decoded = $this->tryDecodeJson($text);
        if (is_array($decoded)) {
            return $decoded;
        }

        $withoutFences = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?? $text;
        $decoded = $this->tryDecodeJson($withoutFences);
        if (is_array($decoded)) {
            return $decoded;
        }

        $candidate = $this->extractBalancedJson($withoutFences);
        if ($candidate !== null) {
            $decoded = $this->tryDecodeJson($candidate);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return ['services' => [], 'warning' => 'invalid_json'];
    }

    private function tryDecodeJson(string $text): mixed
    {
        $decoded = json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        $withoutTrailingCommas = preg_replace('/,\s*([}\]])/', '$1', $text) ?? $text;
        $decoded = json_decode($withoutTrailingCommas, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    private function extractBalancedJson(string $text): ?string
    {
        $start = null;
        $open = '';
        $close = '';

        foreach (str_split($text) as $index => $char) {
            if ($char === '{' || $char === '[') {
                $start = $index;
                $open = $char;
                $close = $char === '{' ? '}' : ']';
                break;
            }
        }

        if ($start === null) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($text);

        for ($index = $start; $index < $length; $index++) {
            $char = $text[$index];

            if ($escaped) {
                $escaped = false;
                continue;
            }

            if ($char === '\\') {
                $escaped = true;
                continue;
            }

            if ($char === '"') {
                $inString = ! $inString;
                continue;
            }

            if ($inString) {
                continue;
            }

            if ($char === $open) {
                $depth++;
            }

            if ($char === $close) {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $index - $start + 1);
                }
            }
        }

        return null;
    }

    private function normalizeService(array $service): ?array
    {
        $name = trim((string) ($service['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        return [
            'name' => Str::limit($name, 160, ''),
            'category' => $this->nullableString($service['category'] ?? $service['type'] ?? null, 160),
            'duration_minutes' => $this->normalizeDuration($service['duration_minutes'] ?? $service['duration'] ?? null),
            'price' => $this->normalizePrice($service['price'] ?? null),
            'description' => $this->nullableString($service['description'] ?? null, 1000),
            'notes' => $this->nullableString($service['notes'] ?? null, 1000),
        ];
    }

    private function normalizeDuration(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && preg_match('/(\d+)/', $value, $matches)) {
            $value = $matches[1];
        }

        $duration = filter_var($value, FILTER_VALIDATE_INT);

        return $duration && $duration >= 1 && $duration <= 1440 ? $duration : null;
    }

    private function normalizePrice(mixed $value): float|int|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = preg_replace('/[^\d.,-]/', '', $value);
            $value = str_replace(',', '.', (string) $value);
        }

        if (! is_numeric($value)) {
            return null;
        }

        $price = round((float) $value, 2);

        if ($price < 0 || $price > 1000000) {
            return null;
        }

        return fmod($price, 1.0) === 0.0 ? (int) $price : $price;
    }

    private function nullableString(mixed $value, int $limit): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : Str::limit($text, $limit, '');
    }

    private function normalizeName(string $name): string
    {
        try {
            return Str::of($name)->ascii()->lower()->squish()->toString();
        } catch (Throwable) {
            return mb_strtolower(trim(preg_replace('/\s+/', ' ', $name) ?? ''));
        }
    }
}
