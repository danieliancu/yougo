<?php

namespace App\Console\Commands;

use App\Enums\OnboardingDraftStatus;
use App\Models\OnboardingDraft;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Purges raw onboarding extraction results (inline JSON or the referenced storage
 * file) once their retention window has passed. Never touches active drafts (pending/
 * analysing/review_required/analysis_failed) — only terminal ones (confirmed/
 * superseded). Idempotent and safe to run repeatedly: a draft is only marked purged
 * once its raw data has actually been removed (or was already gone), and a single
 * draft's failure never stops the rest of the batch.
 */
class PurgeOnboardingRawResultsCommand extends Command
{
    protected $signature = 'onboarding:purge-raw-results';

    protected $description = 'Delete raw onboarding extraction results past their retention window for terminal (non-active) drafts.';

    public function handle(): int
    {
        $purged = 0;
        $failed = 0;

        OnboardingDraft::query()
            ->whereNotNull('raw_result_purge_after')
            ->where('raw_result_purge_after', '<=', now())
            ->whereNull('raw_result_purged_at')
            ->whereNotIn('status', OnboardingDraftStatus::activeValues())
            ->chunkById(100, function ($drafts) use (&$purged, &$failed) {
                foreach ($drafts as $draft) {
                    try {
                        if ($this->purgeDraft($draft)) {
                            $purged++;
                        }
                    } catch (Throwable $exception) {
                        $failed++;
                        Log::warning('onboarding.purge_raw_results.failed', [
                            'draft_id' => $draft->id,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }
            });

        $this->info("Purged {$purged} draft(s); {$failed} left for retry.");

        return self::SUCCESS;
    }

    private function purgeDraft(OnboardingDraft $draft): bool
    {
        $path = $draft->raw_result_storage_path;

        if ($path === null) {
            $this->markPurged($draft);

            return true;
        }

        $disk = Storage::disk(config('onboarding.raw_result.disk', 'local'));

        if (! $disk->exists($path)) {
            // Already gone (previous partial run, manual cleanup, ...) — treat as purged.
            $this->markPurged($draft);

            return true;
        }

        if ($disk->delete($path)) {
            $this->markPurged($draft);

            return true;
        }

        // Deletion failed (returned false): leave the draft exactly as it was so the
        // next scheduled run retries it — never mark purged without an actual deletion.
        Log::warning('onboarding.purge_raw_results.delete_failed', ['draft_id' => $draft->id]);

        return false;
    }

    private function markPurged(OnboardingDraft $draft): void
    {
        $draft->forceFill([
            'raw_extraction_result' => null,
            'raw_result_storage_path' => null,
            'raw_result_purged_at' => now(),
        ])->save();
    }
}
