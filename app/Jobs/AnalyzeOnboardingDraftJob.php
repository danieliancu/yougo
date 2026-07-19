<?php

namespace App\Jobs;

use App\DataTransferObjects\Onboarding\NormalizedExtractionResult;
use App\Enums\OnboardingDraftStatus;
use App\Enums\OnboardingState;
use App\Exceptions\Onboarding\InvalidExtractionResultException;
use App\Models\OnboardingDraft;
use App\Models\Salon;
use App\Services\Onboarding\Analyzer\AnalyzerFailedException;
use App\Services\Onboarding\Analyzer\AnalyzerNotConfiguredException;
use App\Services\Onboarding\Analyzer\OnboardingSourceAnalyzer;
use App\Services\Onboarding\OnboardingDraftStateMachine;
use App\Services\Onboarding\OnboardingEntityDeduplicator;
use App\Services\Onboarding\OnboardingStateMachine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Three phases, matching the pattern in ProcessWhatsAppInboundMessage:
 *
 *  Phase A (short transaction): claim the draft, transition draft+salon to "analysing".
 *  Phase B (no transaction, no lock held): call the analyzer, validate + deduplicate
 *    the result. All external I/O happens here, outside any DB lock.
 *  Phase C (short transaction): re-verify the claim is still ours and the draft hasn't
 *    been superseded, then persist the outcome and transition draft+salon together.
 *
 * A stale/superseded claim's results (and any raw-result artifact it wrote) are
 * discarded without touching the draft or the salon.
 */
class AnalyzeOnboardingDraftJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries;

    public int $timeout;

    public function __construct(public readonly int $draftId)
    {
        $this->tries = (int) config('onboarding.job.tries', 3);
        $this->timeout = (int) config('onboarding.job.timeout', 90);
        $this->onQueue(config('onboarding.job.queue', 'onboarding'));
    }

    public function backoff(): array
    {
        return config('onboarding.job.backoff', [30, 120, 300]);
    }

    public function uniqueId(): string
    {
        return "onboarding-draft-analyze:{$this->draftId}";
    }

    public function uniqueFor(): int
    {
        return $this->timeout + 60;
    }

    public function handle(
        OnboardingSourceAnalyzer $analyzer,
        OnboardingEntityDeduplicator $deduplicator,
        OnboardingStateMachine $salonMachine,
        OnboardingDraftStateMachine $draftMachine,
    ): void {
        $claim = $this->claim($salonMachine, $draftMachine);

        if ($claim === null) {
            return;
        }

        [$claimToken, $attemptNumber] = $claim;

        $draft = OnboardingDraft::query()->find($this->draftId);

        if (! $draft) {
            return;
        }

        $outcome = $this->runAnalysis($analyzer, $deduplicator, $draft, $attemptNumber, $claimToken);

        $stale = $this->finalize($draftMachine, $salonMachine, $claimToken, $attemptNumber, $outcome);

        if ($stale) {
            $this->cleanupArtifact($outcome, $attemptNumber);

            return;
        }

        if (! $outcome['success'] && ! $outcome['permanent']) {
            throw new RuntimeException("Onboarding draft analysis failed: {$outcome['failure_code']}");
        }
    }

    /**
     * Phase A — short transaction: claim the draft and move both state machines to "analysing".
     *
     * @return array{0: string, 1: int}|null
     */
    private function claim(OnboardingStateMachine $salonMachine, OnboardingDraftStateMachine $draftMachine): ?array
    {
        return DB::transaction(function () use ($salonMachine, $draftMachine) {
            $draft = OnboardingDraft::query()->whereKey($this->draftId)->lockForUpdate()->first();

            if (! $draft) {
                return null;
            }

            if (in_array($draft->status, [OnboardingDraftStatus::Confirmed, OnboardingDraftStatus::Superseded], true)) {
                Log::info('onboarding draft analysis job skipped: draft no longer active', [
                    'draft_id' => $draft->id,
                    'status' => $draft->status->value,
                ]);

                return null;
            }

            if ($draft->status === OnboardingDraftStatus::Analysing) {
                if ($draft->isClaimFresh()) {
                    Log::info('onboarding draft analysis job skipped: already in flight', ['draft_id' => $draft->id]);

                    return null;
                }
                // stale claim from a crashed job — fall through and reclaim.
            } elseif (! in_array($draft->status, [OnboardingDraftStatus::Pending, OnboardingDraftStatus::AnalysisFailed], true)) {
                return null;
            }

            $salon = Salon::query()->whereKey($draft->salon_id)->lockForUpdate()->first();

            if (! $salon) {
                return null;
            }

            $claimToken = (string) Str::uuid();

            $draftMachine->transition($draft, OnboardingDraftStatus::Analysing);
            $salonMachine->transition($salon, OnboardingState::Analysing);

            $draft->forceFill([
                'claim_token' => $claimToken,
                'processing_started_at' => now(),
                'attempt_count' => $draft->attempt_count + 1,
                'started_at' => $draft->started_at ?? now(),
            ])->save();

            return [$claimToken, $draft->attempt_count];
        });
    }

    /**
     * Phase B — no DB transaction, no lock held. All external I/O happens here.
     *
     * @return array{success: bool, permanent: bool, processed: ?NormalizedExtractionResult, schema_version: ?string, provider_metadata: array, warnings: array, raw_inline: ?array, raw_storage_path: ?string, raw_checksum: ?string, raw_size: ?int, raw_content_type: ?string, validation_errors: ?array, failure_code: ?string, failure_message: ?string}
     */
    private function runAnalysis(
        OnboardingSourceAnalyzer $analyzer,
        OnboardingEntityDeduplicator $deduplicator,
        OnboardingDraft $draft,
        int $attemptNumber,
        string $claimToken,
    ): array {
        try {
            $analysisResult = $analyzer->analyze($draft);
            $validated = NormalizedExtractionResult::fromArray($analysisResult->normalized);
            $processed = $deduplicator->process($draft, $validated);

            $rawJson = json_encode($analysisResult->raw) ?: '{}';
            $rawSize = strlen($rawJson);
            $maxInline = (int) config('onboarding.raw_result.max_inline_bytes', 65536);

            $rawInline = null;
            $rawStoragePath = null;

            if ($rawSize <= $maxInline) {
                $rawInline = $analysisResult->raw;
            } else {
                $rawStoragePath = "onboarding/raw/{$draft->id}/{$attemptNumber}/{$claimToken}.json";
                Storage::disk(config('onboarding.raw_result.disk', 'local'))->put($rawStoragePath, $rawJson);
            }

            return [
                'success' => true,
                'permanent' => false,
                'processed' => $processed,
                'schema_version' => $analysisResult->schemaVersion,
                'provider_metadata' => $analysisResult->providerMetadata,
                'warnings' => $analysisResult->warnings,
                'raw_inline' => $rawInline,
                'raw_storage_path' => $rawStoragePath,
                'raw_checksum' => hash('sha256', $rawJson),
                'raw_size' => $rawSize,
                'raw_content_type' => 'application/json',
                'validation_errors' => null,
                'failure_code' => null,
                'failure_message' => null,
            ];
        } catch (InvalidExtractionResultException $exception) {
            Log::warning('onboarding draft analysis produced an invalid result', [
                'draft_id' => $draft->id,
                'exception' => $exception::class,
            ]);

            return $this->failedOutcome('invalid_extraction_result', permanent: false, validationErrors: array_values(array_filter([
                $exception->getMessage(),
                ...$exception->errors(),
            ])));
        } catch (AnalyzerNotConfiguredException $exception) {
            Log::warning('onboarding draft analysis skipped: analyzer not configured', ['draft_id' => $draft->id]);

            return $this->failedOutcome($exception->failureCode(), permanent: true);
        } catch (AnalyzerFailedException $exception) {
            Log::warning('onboarding draft analysis failed', [
                'draft_id' => $draft->id,
                'exception' => $exception::class,
                'code' => $exception->failureCode(),
            ]);

            return $this->failedOutcome($exception->failureCode(), permanent: false);
        } catch (Throwable $exception) {
            Log::error('onboarding draft analysis threw an unexpected exception', [
                'draft_id' => $draft->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return $this->failedOutcome('unexpected_error', permanent: false);
        }
    }

    /**
     * @param  list<string>|null  $validationErrors
     * @return array<string, mixed>
     */
    private function failedOutcome(string $failureCode, bool $permanent, ?array $validationErrors = null): array
    {
        return [
            'success' => false,
            'permanent' => $permanent,
            'processed' => null,
            'schema_version' => null,
            'provider_metadata' => [],
            'warnings' => [],
            'raw_inline' => null,
            'raw_storage_path' => null,
            'raw_checksum' => null,
            'raw_size' => null,
            'raw_content_type' => null,
            'validation_errors' => $validationErrors,
            'failure_code' => $failureCode,
            'failure_message' => self::safeFailureMessage($failureCode),
        ];
    }

    /**
     * Phase C — short transaction: re-verify the claim is still ours, then persist.
     *
     * @param  array<string, mixed>  $outcome
     * @return bool whether the result was discarded as stale
     */
    private function finalize(
        OnboardingDraftStateMachine $draftMachine,
        OnboardingStateMachine $salonMachine,
        string $claimToken,
        int $attemptNumber,
        array $outcome,
    ): bool {
        $stale = false;

        DB::transaction(function () use ($draftMachine, $salonMachine, $claimToken, $outcome, &$stale) {
            $draft = OnboardingDraft::query()->whereKey($this->draftId)->lockForUpdate()->first();

            if (! $draft || $draft->claim_token !== $claimToken || $draft->status !== OnboardingDraftStatus::Analysing) {
                $stale = true;

                return;
            }

            $salon = Salon::query()->whereKey($draft->salon_id)->lockForUpdate()->first();

            if (! $salon) {
                $stale = true;

                return;
            }

            if ($outcome['success']) {
                $draft->forceFill([
                    'normalized_extraction_result' => $outcome['processed']->toArray(),
                    'raw_extraction_result' => $outcome['raw_inline'],
                    'raw_result_storage_path' => $outcome['raw_storage_path'],
                    'raw_result_checksum' => $outcome['raw_checksum'],
                    'raw_result_size_bytes' => $outcome['raw_size'],
                    'raw_result_content_type' => $outcome['raw_content_type'],
                    'raw_result_purge_after' => now()->addDays((int) config('onboarding.raw_result.retention_days', 30)),
                    'schema_version' => $outcome['schema_version'],
                    'validation_errors' => null,
                    'failure_code' => null,
                    'failure_message' => null,
                    'finished_at' => now(),
                    'claim_token' => null,
                    'processing_started_at' => null,
                    'revision' => $draft->revision + 1,
                    'metadata' => [
                        ...($draft->metadata ?? []),
                        'last_analysis' => [
                            'provider_metadata' => $outcome['provider_metadata'],
                            'warnings' => $outcome['warnings'],
                        ],
                    ],
                ])->save();

                $draftMachine->transition($draft, OnboardingDraftStatus::ReviewRequired);
                $salonMachine->transition($salon, OnboardingState::ReviewRequired);

                return;
            }

            $history = $draft->metadata['attempt_history'] ?? [];
            $history[] = [
                'attempt' => $draft->attempt_count,
                'started_at' => $draft->processing_started_at?->toISOString(),
                'outcome' => 'failed',
                'failure_code' => $outcome['failure_code'],
            ];
            $history = array_slice($history, -5);

            $draft->forceFill([
                'validation_errors' => $outcome['validation_errors'],
                'failure_code' => $outcome['failure_code'],
                'failure_message' => $outcome['failure_message'],
                'finished_at' => now(),
                'claim_token' => null,
                'processing_started_at' => null,
                'metadata' => [
                    ...($draft->metadata ?? []),
                    'attempt_history' => $history,
                ],
            ])->save();

            $draftMachine->transition($draft, OnboardingDraftStatus::AnalysisFailed);
            $salonMachine->transition($salon, OnboardingState::AnalysisFailed);
        });

        return $stale;
    }

    private function cleanupArtifact(array $outcome, int $attemptNumber): void
    {
        Log::warning('discarded stale onboarding analysis result', [
            'draft_id' => $this->draftId,
            'attempt' => $attemptNumber,
        ]);

        $path = $outcome['raw_storage_path'] ?? null;

        if ($path === null) {
            return;
        }

        try {
            Storage::disk(config('onboarding.raw_result.disk', 'local'))->delete($path);
        } catch (Throwable $exception) {
            Log::warning('failed to clean up stale onboarding raw result artifact', [
                'draft_id' => $this->draftId,
                'attempt' => $attemptNumber,
                'path' => $path,
            ]);
        }
    }

    private static function safeFailureMessage(string $failureCode): string
    {
        return match ($failureCode) {
            'analyzer_busy' => 'The import service is temporarily busy. Please try again shortly.',
            'analyzer_not_configured' => 'Import from this source is not yet available.',
            'invalid_extraction_result' => 'The analysis result did not match the expected format.',
            default => 'The import could not be completed. Please try again.',
        };
    }
}
