<?php

namespace App\Services\Onboarding;

use App\Models\OnboardingDraft;

/**
 * The single safe shape a draft is ever serialized into for the frontend — used by
 * both the JSON import endpoints and the Inertia page controller, so neither can
 * drift from the other. Never includes raw_extraction_result, its storage path, AI
 * prompts, or provider/model identifiers.
 */
class OnboardingDraftPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(OnboardingDraft $draft): array
    {
        return [
            'id' => $draft->id,
            'status' => $draft->status->value,
            'source_type' => $draft->source_type,
            'source_url' => $draft->source_url,
            'revision' => $draft->revision,
            'attempt_count' => $draft->attempt_count,
            'normalized_extraction_result' => $draft->normalized_extraction_result,
            'validation_errors' => $draft->validation_errors,
            'failure_code' => $draft->failure_code,
            'failure_message' => $draft->failure_message,
            'started_at' => $draft->started_at?->toISOString(),
            'finished_at' => $draft->finished_at?->toISOString(),
            'confirmed_at' => $draft->confirmed_at?->toISOString(),
            'superseded_at' => $draft->superseded_at?->toISOString(),
            'analysis_progress' => self::analysisProgress($draft),
        ];
    }

    /**
     * A safe subset of metadata.last_analysis (set by AnalyzeOnboardingDraftJob's
     * Phase C) for the UI to show crawl progress — never the raw field, never
     * prompts, never storage paths.
     *
     * @return array<string, mixed>|null
     */
    private static function analysisProgress(OnboardingDraft $draft): ?array
    {
        $providerMetadata = $draft->metadata['last_analysis']['provider_metadata'] ?? null;

        if ($providerMetadata === null) {
            return null;
        }

        return [
            'pages_discovered' => $providerMetadata['pages_discovered'] ?? null,
            'pages_processed' => $providerMetadata['pages_processed'] ?? null,
            'warnings' => $draft->metadata['last_analysis']['warnings'] ?? [],
            'stop_reason' => $providerMetadata['stop_reason'] ?? null,
        ];
    }
}
