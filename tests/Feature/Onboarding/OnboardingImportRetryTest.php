<?php

namespace Tests\Feature\Onboarding;

use App\Enums\OnboardingDraftStatus;
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
use Illuminate\Support\Str;
use Tests\TestCase;

class OnboardingImportRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_retry_is_blocked_while_a_fresh_claim_is_in_progress(): void
    {
        [, $user, $draft] = $this->createAnalysingDraft(fresh: true);

        $response = $this->actingAs($user)->postJson("/onboarding/import/{$draft->id}/retry");

        $response->assertStatus(409);
        $this->assertSame(OnboardingDraftStatus::Analysing, $draft->refresh()->status);
    }

    public function test_retry_is_allowed_after_a_stale_claim(): void
    {
        config(['queue.default' => 'sync']);
        $this->app->instance(OnboardingSourceAnalyzer::class, new FakeOnboardingSourceAnalyzer);

        [, $user, $draft] = $this->createAnalysingDraft(fresh: false);

        $response = $this->actingAs($user)->postJson("/onboarding/import/{$draft->id}/retry");

        $response->assertOk();
        $this->assertSame('review_required', $response->json('status'));
    }

    public function test_attempt_count_reaches_three_after_two_failures_then_a_success(): void
    {
        [, , $draft] = $this->createAnalysingDraft(fresh: false, status: OnboardingDraftStatus::Pending);

        $failing = (new FakeOnboardingSourceAnalyzer)->willThrow(new AnalyzerBusyException('busy'));
        $this->app->instance(OnboardingSourceAnalyzer::class, $failing);

        $job = fn () => (new AnalyzeOnboardingDraftJob($draft->id))->handle(
            $this->app->make(OnboardingSourceAnalyzer::class),
            $this->app->make(OnboardingEntityDeduplicator::class),
            $this->app->make(OnboardingStateMachine::class),
            $this->app->make(OnboardingDraftStateMachine::class),
        );

        try {
            $job();
        } catch (\RuntimeException) {
        }
        $this->assertSame(1, $draft->refresh()->attempt_count);
        $this->assertSame(OnboardingDraftStatus::AnalysisFailed, $draft->status);

        try {
            $job();
        } catch (\RuntimeException) {
        }
        $this->assertSame(2, $draft->refresh()->attempt_count);

        $this->app->instance(OnboardingSourceAnalyzer::class, new FakeOnboardingSourceAnalyzer);
        $job();

        $draft->refresh();
        $this->assertSame(3, $draft->attempt_count);
        $this->assertSame(OnboardingDraftStatus::ReviewRequired, $draft->status);
    }

    /**
     * @return array{0: Salon, 1: User, 2: OnboardingDraft}
     */
    private function createAnalysingDraft(bool $fresh, OnboardingDraftStatus $status = OnboardingDraftStatus::Analysing): array
    {
        $user = User::factory()->create();
        /** @var Salon $salon */
        $salon = $user->salon()->create(['name' => 'Test Salon']);

        $draft = OnboardingDraft::query()->create([
            'salon_id' => $salon->id,
            'created_by_user_id' => $user->id,
            'source_type' => 'url',
            'source_url' => 'http://93.184.216.34/',
            'normalized_source_url' => 'http://93.184.216.34/',
            'status' => $status,
            'claim_token' => $status === OnboardingDraftStatus::Analysing ? (string) Str::uuid() : null,
            'processing_started_at' => $status === OnboardingDraftStatus::Analysing
                ? ($fresh ? now() : now()->subMinutes(10))
                : null,
        ]);

        return [$salon, $user, $draft];
    }
}
