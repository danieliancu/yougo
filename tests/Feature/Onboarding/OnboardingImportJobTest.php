<?php

namespace Tests\Feature\Onboarding;

use App\Enums\OnboardingDraftStatus;
use App\Enums\OnboardingState;
use App\Jobs\AnalyzeOnboardingDraftJob;
use App\Models\OnboardingDraft;
use App\Models\Salon;
use App\Models\User;
use App\Services\Onboarding\Analyzer\AnalyzerBusyException;
use App\Services\Onboarding\Analyzer\FakeOnboardingSourceAnalyzer;
use App\Services\Onboarding\Analyzer\OnboardingSourceAnalyzer;
use App\Services\Onboarding\OnboardingDraftStateMachine;
use App\Services\Onboarding\OnboardingEntityDeduplicator;
use App\Services\Onboarding\OnboardingStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OnboardingImportJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_analysis_updates_draft_and_salon_together(): void
    {
        $this->app->instance(OnboardingSourceAnalyzer::class, new FakeOnboardingSourceAnalyzer);

        [$salon, $draft] = $this->createPendingDraft();

        (new AnalyzeOnboardingDraftJob($draft->id))->handle(
            $this->app->make(OnboardingSourceAnalyzer::class),
            $this->app->make(OnboardingEntityDeduplicator::class),
            $this->app->make(OnboardingStateMachine::class),
            $this->app->make(OnboardingDraftStateMachine::class),
        );

        $draft->refresh();
        $salon->refresh();

        $this->assertSame(OnboardingDraftStatus::ReviewRequired, $draft->status);
        $this->assertSame(OnboardingState::ReviewRequired, $salon->onboarding_state);
        $this->assertSame(1, $draft->attempt_count);
        $this->assertNotNull($draft->normalized_extraction_result);
        $this->assertNull($draft->claim_token);
        $this->assertSame(1, $draft->revision);
    }

    public function test_failed_analysis_updates_draft_and_salon_together(): void
    {
        $analyzer = (new FakeOnboardingSourceAnalyzer)->willThrow(new AnalyzerBusyException('busy'));
        $this->app->instance(OnboardingSourceAnalyzer::class, $analyzer);

        [$salon, $draft] = $this->createPendingDraft();

        $this->expectException(\RuntimeException::class);

        try {
            (new AnalyzeOnboardingDraftJob($draft->id))->handle(
                $this->app->make(OnboardingSourceAnalyzer::class),
                $this->app->make(OnboardingEntityDeduplicator::class),
                $this->app->make(OnboardingStateMachine::class),
                $this->app->make(OnboardingDraftStateMachine::class),
            );
        } finally {
            $draft->refresh();
            $salon->refresh();

            $this->assertSame(OnboardingDraftStatus::AnalysisFailed, $draft->status);
            $this->assertSame(OnboardingState::AnalysisFailed, $salon->onboarding_state);
            $this->assertSame('analyzer_busy', $draft->failure_code);
            $this->assertNull($draft->claim_token);
        }
    }

    public function test_no_open_db_transaction_during_analyzer_call(): void
    {
        // RefreshDatabase wraps the whole test in its own transaction, so the baseline
        // level here is 1 (not 0) — Phase B must not add any nesting on top of that,
        // i.e. it must not still be holding Phase A's transaction open.
        $baselineTransactionLevel = DB::transactionLevel();

        $analyzer = (new FakeOnboardingSourceAnalyzer)->willReturn(function ($draft) use ($baselineTransactionLevel) {
            if (DB::transactionLevel() !== $baselineTransactionLevel) {
                throw new \RuntimeException('Phase B ran inside an open transaction.');
            }

            return FakeOnboardingSourceAnalyzer::defaultResult($draft);
        });
        $this->app->instance(OnboardingSourceAnalyzer::class, $analyzer);

        [, $draft] = $this->createPendingDraft();

        (new AnalyzeOnboardingDraftJob($draft->id))->handle(
            $this->app->make(OnboardingSourceAnalyzer::class),
            $this->app->make(OnboardingEntityDeduplicator::class),
            $this->app->make(OnboardingStateMachine::class),
            $this->app->make(OnboardingDraftStateMachine::class),
        );

        $this->assertSame(OnboardingDraftStatus::ReviewRequired, $draft->refresh()->status);
    }

    public function test_stale_claim_token_cannot_save_results(): void
    {
        [$salon, $draft] = $this->createPendingDraft();

        $job = new AnalyzeOnboardingDraftJob($draft->id);
        $salonMachine = $this->app->make(OnboardingStateMachine::class);
        $draftMachine = $this->app->make(OnboardingDraftStateMachine::class);
        $deduplicator = $this->app->make(OnboardingEntityDeduplicator::class);

        $claimMethod = new \ReflectionMethod($job, 'claim');
        $claimMethod->setAccessible(true);
        [$claimToken, $attemptNumber] = $claimMethod->invoke($job, $salonMachine, $draftMachine);

        $draft->refresh();
        $analyzer = new FakeOnboardingSourceAnalyzer;
        $runAnalysisMethod = new \ReflectionMethod($job, 'runAnalysis');
        $runAnalysisMethod->setAccessible(true);
        $outcome = $runAnalysisMethod->invoke($job, $analyzer, $deduplicator, $draft, $attemptNumber, $claimToken);

        // Simulate a second, later job instance reclaiming the same draft (e.g. after
        // this "job" appeared to hang and was reclaimed via retry) before this job's
        // Phase C runs — the row now holds a different claim_token.
        $draft->forceFill(['claim_token' => (string) Str::uuid()])->save();

        $finalizeMethod = new \ReflectionMethod($job, 'finalize');
        $finalizeMethod->setAccessible(true);
        $stale = $finalizeMethod->invoke($job, $draftMachine, $salonMachine, $claimToken, $attemptNumber, $outcome);

        $this->assertTrue($stale);
        $draft->refresh();
        $salon->refresh();

        // Nothing from the stale job's results was persisted.
        $this->assertNull($draft->normalized_extraction_result);
        $this->assertSame(OnboardingDraftStatus::Analysing, $draft->status);
        $this->assertSame(OnboardingState::Analysing, $salon->onboarding_state);
    }

    public function test_job_for_a_superseded_draft_makes_no_changes(): void
    {
        [$salon, $draft] = $this->createPendingDraft();

        $job = new AnalyzeOnboardingDraftJob($draft->id);
        $salonMachine = $this->app->make(OnboardingStateMachine::class);
        $draftMachine = $this->app->make(OnboardingDraftStateMachine::class);

        $claimMethod = new \ReflectionMethod($job, 'claim');
        $claimMethod->setAccessible(true);
        $claimMethod->invoke($job, $salonMachine, $draftMachine);

        // The draft gets superseded (e.g. the user started a new import) while this
        // job's Phase B would have been running.
        $draft->refresh();
        $draftMachine->transition($draft, OnboardingDraftStatus::Superseded);
        $salonStateBefore = $salon->refresh()->onboarding_state;

        $secondClaim = $claimMethod->invoke($job, $salonMachine, $draftMachine);

        $this->assertNull($secondClaim);
        $this->assertSame($salonStateBefore, $salon->refresh()->onboarding_state);
    }

    /**
     * @return array{0: Salon, 1: OnboardingDraft}
     */
    private function createPendingDraft(): array
    {
        $user = User::factory()->create();
        /** @var Salon $salon */
        $salon = $user->salon()->create(['name' => 'Test Salon']);

        $draft = OnboardingDraft::query()->create([
            'salon_id' => $salon->id,
            'created_by_user_id' => $user->id,
            'source_type' => 'url',
            'source_url' => 'https://example.com',
            'normalized_source_url' => 'https://example.com',
            'status' => OnboardingDraftStatus::Pending,
        ]);

        return [$salon, $draft];
    }
}
