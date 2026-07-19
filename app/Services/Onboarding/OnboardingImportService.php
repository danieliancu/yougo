<?php

namespace App\Services\Onboarding;

use App\DataTransferObjects\Onboarding\NormalizedExtractionResult;
use App\Enums\OnboardingDraftStatus;
use App\Enums\OnboardingState;
use App\Exceptions\Onboarding\OnboardingImportConflictException;
use App\Exceptions\Onboarding\OnboardingImportLockedException;
use App\Exceptions\Onboarding\OnboardingRevisionConflictException;
use App\Jobs\AnalyzeOnboardingDraftJob;
use App\Models\OnboardingDraft;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Orchestrates the draft lifecycle around the job: starting a new import (including
 * the single-active-draft-per-salon rule and salon/draft supersession sync), retrying
 * a failed/stuck one, and looking up the current active draft.
 */
class OnboardingImportService
{
    public function __construct(
        private readonly OnboardingUrlNormalizer $urlNormalizer,
        private readonly OnboardingUrlValidator $urlValidator,
        private readonly OnboardingStateMachine $salonMachine,
        private readonly OnboardingDraftStateMachine $draftMachine,
    ) {}

    public function start(Salon $salon, ?User $user, string $sourceType, ?string $sourceUrl): OnboardingDraft
    {
        $normalizedUrl = null;

        if ($sourceType === 'url') {
            if (! $sourceUrl) {
                throw new InvalidArgumentException('source_url is required for source_type=url.');
            }

            $normalizedUrl = $this->urlNormalizer->normalize($sourceUrl);
            $this->urlValidator->validate($normalizedUrl);
        }

        $idempotencyKey = hash('sha256', "{$salon->id}|{$sourceType}|".($normalizedUrl ?? ''));

        $draft = DB::transaction(function () use ($salon, $user, $sourceType, $sourceUrl, $normalizedUrl, $idempotencyKey) {
            // Lock the salon row FIRST and re-read its state before checking
            // locksNewImport() — this serializes start() against a concurrent
            // confirm() (which also locks the salon), so the check can't race with a
            // confirmation that reaches identity_ready at the same moment.
            $lockedSalon = Salon::query()->whereKey($salon->id)->lockForUpdate()->first();

            if (! $lockedSalon) {
                throw new RuntimeException('Salon not found.');
            }

            if ($lockedSalon->onboarding_state->locksNewImport()) {
                throw new OnboardingImportLockedException(
                    'This business has already completed identity setup; re-importing is a separate feature, not a new onboarding run.'
                );
            }

            $active = OnboardingDraft::query()
                ->where('salon_id', $lockedSalon->id)
                ->active()
                ->lockForUpdate()
                ->first();

            if ($active) {
                if ($active->normalized_source_url === $normalizedUrl) {
                    return $active;
                }

                if ($active->status === OnboardingDraftStatus::Analysing && $active->isClaimFresh()) {
                    throw new OnboardingImportConflictException(
                        'An import is already in progress for a different source.',
                        'import_in_progress'
                    );
                }

                $this->supersedeActiveDraft($active, $lockedSalon);
            }

            return $this->createDraft($lockedSalon, $user, $sourceType, $sourceUrl, $normalizedUrl, $idempotencyKey);
        });

        if ($draft->wasRecentlyCreated) {
            AnalyzeOnboardingDraftJob::dispatch($draft->id);

            // On a synchronous queue connection (e.g. in tests), the job above may
            // have already run and mutated this row — refresh so callers always see
            // the current DB state rather than a stale in-memory copy from before
            // dispatch. A no-op extra query on a real async queue.
            $draft->refresh();
        }

        return $draft;
    }

    public function retry(OnboardingDraft $draft): OnboardingDraft
    {
        DB::transaction(function () use ($draft) {
            $fresh = OnboardingDraft::query()->whereKey($draft->id)->lockForUpdate()->first();

            if (! $fresh) {
                throw new RuntimeException('Draft not found.');
            }

            if ($fresh->status === OnboardingDraftStatus::Analysing) {
                if ($fresh->isClaimFresh()) {
                    throw new OnboardingImportConflictException(
                        'This import is already being analysed.',
                        'already_processing'
                    );
                }

                return; // stale claim — allow the job to reclaim it in Phase A.
            }

            if (! in_array($fresh->status, [OnboardingDraftStatus::Pending, OnboardingDraftStatus::AnalysisFailed], true)) {
                throw new OnboardingImportConflictException(
                    'This draft cannot be retried in its current state.',
                    'not_retryable'
                );
            }
        });

        AnalyzeOnboardingDraftJob::dispatch($draft->id);

        return $draft->refresh();
    }

    public function active(Salon $salon): ?OnboardingDraft
    {
        return $salon->activeOnboardingDraft();
    }

    /**
     * Applies pre-confirm corrections to specific facts, addressed by their stable
     * fact path (e.g. "business.name", "locations.<fingerprint>.opening_hours").
     * Rejected while analysis is in progress, so it can never race with the job
     * writing a fresh analysis result over the same row.
     *
     * @param  array<string, array{value: mixed}>  $corrections
     */
    public function updateDraft(OnboardingDraft $draft, int $expectedRevision, array $corrections): OnboardingDraft
    {
        return DB::transaction(function () use ($draft, $expectedRevision, $corrections) {
            $locked = OnboardingDraft::query()->whereKey($draft->id)->lockForUpdate()->first();

            if (! $locked) {
                throw new RuntimeException('Draft not found.');
            }

            if ($locked->status === OnboardingDraftStatus::Analysing) {
                throw new OnboardingImportConflictException('Cannot edit while analysis is in progress.', 'analysing');
            }

            if (! in_array($locked->status, [OnboardingDraftStatus::ReviewRequired, OnboardingDraftStatus::AnalysisFailed], true)) {
                throw new OnboardingImportConflictException('This draft cannot be edited in its current state.', 'not_editable');
            }

            if ($locked->revision !== $expectedRevision) {
                throw new OnboardingRevisionConflictException($locked->revision);
            }

            // Re-validates the stored payload as a shape guard before editing it.
            NormalizedExtractionResult::fromArray($locked->normalized_extraction_result ?? []);

            $raw = $locked->normalized_extraction_result ?? [];

            foreach ($corrections as $path => $correction) {
                $this->applyCorrection($raw, (string) $path, $correction['value'] ?? null);
            }

            // Re-validate after edits so a malformed correction can never be persisted.
            NormalizedExtractionResult::fromArray($raw);

            $locked->forceFill([
                'normalized_extraction_result' => $raw,
                'revision' => $locked->revision + 1,
            ])->save();

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function applyCorrection(array &$raw, string $path, mixed $value): void
    {
        $segments = explode('.', $path);

        if (count($segments) === 2 && in_array($segments[0], ['business', 'contact'], true)) {
            [$section, $field] = $segments;

            if (! isset($raw[$section][$field]) || ! is_array($raw[$section][$field])) {
                return;
            }

            $raw[$section][$field]['value'] = $value;
            $raw[$section][$field]['requires_confirmation'] = false;

            return;
        }

        if (count($segments) === 3 && in_array($segments[0], ['locations', 'services'], true)) {
            [$collection, $fingerprint, $field] = $segments;

            foreach ($raw[$collection] ?? [] as $index => $entity) {
                if (($entity['fingerprint'] ?? null) === $fingerprint && isset($entity[$field]) && is_array($entity[$field])) {
                    $raw[$collection][$index][$field]['value'] = $value;
                    $raw[$collection][$index][$field]['requires_confirmation'] = false;

                    return;
                }
            }
        }
    }

    private function supersedeActiveDraft(OnboardingDraft $draft, Salon $salon): void
    {
        $previousStatus = $draft->status;

        $this->draftMachine->transition($draft, OnboardingDraftStatus::Superseded);
        $draft->forceFill(['superseded_at' => now()])->save();

        match ($previousStatus) {
            OnboardingDraftStatus::ReviewRequired,
            OnboardingDraftStatus::AnalysisFailed,
            OnboardingDraftStatus::Analysing => $this->salonMachine->transition($salon, OnboardingState::SourcePending),
            default => null, // Pending: salon never advanced past source_pending — no-op.
        };
    }

    private function createDraft(
        Salon $salon,
        ?User $user,
        string $sourceType,
        ?string $sourceUrl,
        ?string $normalizedUrl,
        string $idempotencyKey,
    ): OnboardingDraft {
        try {
            return OnboardingDraft::query()->create([
                'salon_id' => $salon->id,
                'created_by_user_id' => $user?->id,
                'source_type' => $sourceType,
                'source_url' => $sourceUrl,
                'normalized_source_url' => $normalizedUrl,
                'schema_version' => NormalizedExtractionResult::CURRENT_SCHEMA_VERSION,
                'status' => OnboardingDraftStatus::Pending,
                'idempotency_key' => $idempotencyKey,
            ]);
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $existing = OnboardingDraft::query()
                ->where('salon_id', $salon->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first() ?? $salon->activeOnboardingDraft();

            if ($existing) {
                return $existing;
            }

            throw $exception;
        }
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return $exception->getCode() === '23000' || str_contains($exception->getMessage(), '23000');
    }
}
