<?php

namespace App\Http\Controllers;

use App\Services\ServiceImport\ServiceImageImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ServiceImageImportController extends Controller
{
    public function analyze(Request $request, ServiceImageImportService $importer): JsonResponse
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(max(75, (int) config('services.gemini.vision_timeout', 60) + 15));
        }

        $data = $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        try {
            $result = $importer->analyze($data['image']);
        } catch (Throwable $exception) {
            $temporaryCapacityFailure = $this->isTemporaryAiCapacityFailure($exception);

            Log::warning('Service image import analysis failed', [
                'user_id' => $request->user()?->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'temporary_capacity_failure' => $temporaryCapacityFailure,
            ]);

            return response()->json([
                'services' => [],
                'warning' => $temporaryCapacityFailure ? 'ai_service_busy' : 'analysis_failed',
                'message' => $temporaryCapacityFailure ? 'AI service is temporarily busy.' : 'AI image analysis failed.',
                'details' => app()->isLocal() ? $exception->getMessage() : null,
            ], $temporaryCapacityFailure ? 503 : 502);
        }

        return response()->json([
            'services' => $result['services'],
            'warning' => $result['warning'] ?? null,
        ]);
    }

    private function isTemporaryAiCapacityFailure(Throwable $exception): bool
    {
        $message = Str::lower($exception->getMessage());

        return Str::contains($message, [
            'high demand',
            'temporarily unavailable',
            'overloaded',
            'too many requests',
            'rate limit',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $salon = $request->user()->salon;
        abort_unless($salon, 403);

        $data = $request->validate([
            'services' => ['required', 'array', 'max:50'],
            'services.*.name' => ['required', 'string', 'max:160'],
            'services.*.category' => ['nullable', 'string', 'max:160'],
            'services.*.duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'services.*.price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'services.*.description' => ['nullable', 'string', 'max:1000'],
            'services.*.notes' => ['nullable', 'string', 'max:1000'],
            'services.*.selected' => ['nullable', 'boolean'],
        ]);

        $existing = $salon->services()
            ->pluck('name')
            ->mapWithKeys(fn (string $name) => [$this->normalizeName($name) => true])
            ->all();
        $createdCount = 0;
        $skipped = [];
        $importedCategories = [];
        $defaultLocationIds = $salon->locations()->count() === 1
            ? [$salon->locations()->value('id')]
            : [];

        foreach ($data['services'] as $service) {
            if (($service['selected'] ?? true) === false) {
                continue;
            }

            $name = trim((string) $service['name']);
            $normalizedName = $this->normalizeName($name);

            if ($normalizedName === '' || isset($existing[$normalizedName])) {
                $skipped[] = ['name' => $name, 'reason' => 'duplicate'];
                continue;
            }

            $category = trim((string) ($service['category'] ?? ''));
            $notes = collect([$service['description'] ?? null, $service['notes'] ?? null])
                ->map(fn ($value) => trim((string) $value))
                ->filter()
                ->unique()
                ->implode("\n\n");

            $salon->services()->create([
                'name' => $name,
                'type' => $category,
                'price' => isset($service['price']) && $service['price'] !== null ? (string) $service['price'] : '',
                'currency' => null,
                'duration' => $service['duration_minutes'] ?? 30,
                'location_ids' => $defaultLocationIds,
                'notes' => $notes,
            ]);

            if ($category !== '') {
                $importedCategories[] = $category;
            }

            $existing[$normalizedName] = true;
            $createdCount++;
        }

        $this->mergeServiceCategories($salon, $importedCategories);

        return response()->json([
            'created_count' => $createdCount,
            'skipped' => $skipped,
        ]);
    }

    private function normalizeName(string $name): string
    {
        return Str::of($name)->ascii()->lower()->squish()->toString();
    }

    private function mergeServiceCategories($salon, array $categories): void
    {
        $merged = collect($salon->service_categories ?? [])
            ->merge($categories)
            ->map(fn ($category) => trim((string) $category))
            ->filter()
            ->unique(fn ($category) => $this->normalizeName($category))
            ->values()
            ->all();

        if ($merged !== ($salon->service_categories ?? [])) {
            $salon->update(['service_categories' => $merged]);
        }
    }
}
