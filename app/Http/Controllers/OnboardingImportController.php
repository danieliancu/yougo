<?php

namespace App\Http\Controllers;

use App\DataTransferObjects\Onboarding\ConfirmedSelections;
use App\Exceptions\Onboarding\IncompleteOnboardingDraftException;
use App\Exceptions\Onboarding\InvalidOnboardingUrlException;
use App\Exceptions\Onboarding\MissingFactDecisionsException;
use App\Exceptions\Onboarding\OnboardingImportConflictException;
use App\Exceptions\Onboarding\OnboardingImportLockedException;
use App\Exceptions\Onboarding\OnboardingRevisionConflictException;
use App\Http\Requests\Onboarding\ConfirmOnboardingDraftRequest;
use App\Http\Requests\Onboarding\RetryOnboardingImportRequest;
use App\Http\Requests\Onboarding\StartOnboardingImportRequest;
use App\Http\Requests\Onboarding\UpdateOnboardingDraftRequest;
use App\Models\OnboardingDraft;
use App\Services\Onboarding\OnboardingDraftConfirmationService;
use App\Services\Onboarding\OnboardingImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Import-first onboarding endpoints. Deliberately thin — all logic lives in
 * OnboardingImportService / OnboardingDraftConfirmationService. Every draft-scoped
 * action is authorized against the current user's salon (salon-based, not user-based,
 * tenant isolation — see OnboardingDraft::salon_id), matching the existing
 * abort_unless convention used elsewhere in this codebase (e.g. LocationController).
 *
 * Responses never include raw_extraction_result or its storage path.
 */
class OnboardingImportController extends Controller
{
    public function __construct(
        private readonly OnboardingImportService $importService,
        private readonly OnboardingDraftConfirmationService $confirmationService,
    ) {}

    public function start(StartOnboardingImportRequest $request): JsonResponse
    {
        $salon = $request->user()->salon;
        abort_unless($salon, 403);

        try {
            $draft = $this->importService->start(
                $salon,
                $request->user(),
                (string) $request->input('source_type'),
                $request->input('source_url'),
            );
        } catch (OnboardingImportLockedException|OnboardingImportConflictException $exception) {
            return $this->conflictResponse($exception);
        } catch (InvalidOnboardingUrlException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => ['source_url' => [$exception->getMessage()]],
            ], 422);
        }

        return response()->json($this->serialize($draft));
    }

    public function active(Request $request): JsonResponse
    {
        $salon = $request->user()->salon;
        abort_unless($salon, 403);

        $draft = $this->importService->active($salon);

        return response()->json(['draft' => $draft ? $this->serialize($draft) : null]);
    }

    public function status(Request $request, OnboardingDraft $onboardingDraft): JsonResponse
    {
        $this->authorizeOwner($request, $onboardingDraft);

        return response()->json($this->serialize($onboardingDraft));
    }

    public function retry(RetryOnboardingImportRequest $request, OnboardingDraft $onboardingDraft): JsonResponse
    {
        $this->authorizeOwner($request, $onboardingDraft);

        try {
            $draft = $this->importService->retry($onboardingDraft);
        } catch (OnboardingImportConflictException $exception) {
            return $this->conflictResponse($exception);
        }

        return response()->json($this->serialize($draft));
    }

    public function update(UpdateOnboardingDraftRequest $request, OnboardingDraft $onboardingDraft): JsonResponse
    {
        $this->authorizeOwner($request, $onboardingDraft);

        try {
            $draft = $this->importService->updateDraft(
                $onboardingDraft,
                (int) $request->input('expected_revision'),
                $request->input('corrections', []),
            );
        } catch (OnboardingRevisionConflictException $exception) {
            return $this->revisionConflictResponse($exception);
        } catch (OnboardingImportConflictException $exception) {
            return $this->conflictResponse($exception);
        }

        return response()->json($this->serialize($draft));
    }

    public function confirm(ConfirmOnboardingDraftRequest $request, OnboardingDraft $onboardingDraft): JsonResponse
    {
        $this->authorizeOwner($request, $onboardingDraft);

        $selections = ConfirmedSelections::fromArray($request->validated());

        try {
            $salon = $this->confirmationService->confirm($onboardingDraft, $request->user(), $selections);
        } catch (OnboardingRevisionConflictException $exception) {
            return $this->revisionConflictResponse($exception);
        } catch (MissingFactDecisionsException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'missing_fact_decisions' => $exception->missingPaths,
            ], 422);
        } catch (IncompleteOnboardingDraftException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'failed_conditions' => $exception->failedConditions,
            ], 422);
        }

        return response()->json([
            'draft' => $this->serialize($onboardingDraft->refresh()),
            'salon' => [
                'id' => $salon->id,
                'onboarding_state' => $salon->onboarding_state->value,
            ],
        ]);
    }

    private function authorizeOwner(Request $request, OnboardingDraft $draft): void
    {
        abort_unless($draft->salon_id === $request->user()->salon?->id, 403);
    }

    private function conflictResponse(Throwable $exception): JsonResponse
    {
        $reasonCode = method_exists($exception, 'reasonCode') ? $exception->reasonCode() : 'conflict';

        return response()->json(['message' => $exception->getMessage(), 'reason' => $reasonCode], 409);
    }

    private function revisionConflictResponse(OnboardingRevisionConflictException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'current_revision' => $exception->currentRevision,
        ], 409);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(OnboardingDraft $draft): array
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
            'analysis_progress' => $this->analysisProgress($draft),
        ];
    }

    /**
     * A safe subset of metadata.last_analysis (set by AnalyzeOnboardingDraftJob's
     * Phase C) for a future UI to show crawl progress — never the raw field, never
     * prompts, never storage paths.
     *
     * @return array<string, mixed>|null
     */
    private function analysisProgress(OnboardingDraft $draft): ?array
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
