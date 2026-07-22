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
use App\Services\Business\IndustryDefaultsService;
use App\Services\Business\IndustryMatcher;
use App\Services\Onboarding\OnboardingDraftConfirmationService;
use App\Services\Onboarding\OnboardingDraftPresenter;
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
        private readonly IndustryMatcher $industryMatcher,
        private readonly IndustryDefaultsService $industryDefaults,
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

        // Captured before confirm() writes the AI-extracted business_type text onto the
        // salon — this is the "industry the user selected" side of the conflict check.
        $selectedBusinessType = $onboardingDraft->salon->business_type;
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

        $industryReview = $this->applyIndustryDefaultsAfterConfirmation($salon, $onboardingDraft, $selectedBusinessType);

        return response()->json([
            'draft' => $this->serialize($onboardingDraft->refresh()),
            'salon' => [
                'id' => $salon->id,
                'onboarding_state' => $salon->onboarding_state->value,
            ],
            'industry_review' => $industryReview,
        ]);
    }

    /**
     * Purely additive, non-transactional follow-up to a successful confirm() — never
     * touches the confirmation service's own transaction, so it carries none of that
     * critical path's regression risk. Detects a conflict between the industry the user
     * selected (business_type before this confirm) and the one the analyzer detected from
     * the raw extracted text (via curated aliases, not the possibly free-form text now
     * written to salon.business_type), then always populates the capability recommendation
     * — the frontend shows the confirmation screen in every case (Task 4 §10).
     *
     * @return array{conflict: bool, detected_business_type: ?string, selected_business_type: ?string, recommended_primary_capability: ?string, recommended_secondary_capabilities: list<string>}
     */
    private function applyIndustryDefaultsAfterConfirmation($salon, OnboardingDraft $onboardingDraft, ?string $selectedBusinessType): array
    {
        $rawBusinessTypeText = $onboardingDraft->normalized_extraction_result['business']['business_type']['value'] ?? null;
        $matched = $this->industryMatcher->match(is_string($rawBusinessTypeText) ? $rawBusinessTypeText : null);

        $detectedBusinessType = $matched['matched'] ? $matched['business_type'] : null;
        $conflict = $detectedBusinessType !== null && $selectedBusinessType !== null && $detectedBusinessType !== $selectedBusinessType;

        // Prefer the confidently-detected slug for the recommendation; an uncertain/
        // conflicting detection still lets the user's own selection drive the
        // recommendation, since that's the only side we're sure is a real taxonomy slug.
        $recommendationSourceSlug = $detectedBusinessType ?? $selectedBusinessType ?? $salon->business_type;

        if ($recommendationSourceSlug) {
            $this->industryDefaults->applyRecommendation($salon, $recommendationSourceSlug, $matched['industry'] ?? null, $onboardingDraft);
            $salon->refresh();
        }

        return [
            'conflict' => $conflict,
            'detected_business_type' => $detectedBusinessType,
            'selected_business_type' => $selectedBusinessType,
            'recommended_primary_capability' => $salon->recommended_primary_capability,
            'recommended_secondary_capabilities' => $salon->recommended_secondary_capabilities ?? [],
        ];
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
        return OnboardingDraftPresenter::present($draft);
    }
}
