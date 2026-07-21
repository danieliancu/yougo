<?php

namespace Tests\Feature\Onboarding;

use App\Enums\OnboardingDraftStatus;
use App\Enums\OnboardingState;
use App\Exceptions\Onboarding\OnboardingImportConflictException;
use App\Exceptions\Onboarding\OnboardingImportLockedException;
use App\Jobs\AnalyzeOnboardingDraftJob;
use App\Models\OnboardingDraft;
use App\Models\Salon;
use App\Models\User;
use App\Services\Onboarding\OnboardingDraftStateMachine;
use App\Services\Onboarding\OnboardingImportService;
use App\Services\Onboarding\OnboardingStateMachine;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

class OnboardingImportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_creates_a_draft_and_dispatches_the_job(): void
    {
        Bus::fake();
        [$salon, $user] = $this->createSalonAndUser();

        $draft = $this->service()->start($salon, $user, 'url', 'http://93.184.216.34/');

        $this->assertSame(OnboardingDraftStatus::Pending, $draft->status);
        $this->assertSame('http://93.184.216.34', $draft->normalized_source_url);
        Bus::assertDispatched(AnalyzeOnboardingDraftJob::class, fn ($job) => $job->draftId === $draft->id);
    }

    public function test_double_submit_same_url_returns_the_same_draft(): void
    {
        Bus::fake();
        [$salon, $user] = $this->createSalonAndUser();

        $first = $this->service()->start($salon, $user, 'url', 'http://93.184.216.34/');
        $second = $this->service()->start($salon, $user, 'url', 'http://93.184.216.34/');

        $this->assertSame($first->id, $second->id);
        Bus::assertDispatchedTimes(AnalyzeOnboardingDraftJob::class, 1);
    }

    public function test_different_url_while_analysing_with_fresh_claim_is_rejected(): void
    {
        Bus::fake();
        [$salon, $user] = $this->createSalonAndUser();

        $draft = $this->service()->start($salon, $user, 'url', 'http://93.184.216.34/');
        $draft->forceFill([
            'status' => OnboardingDraftStatus::Analysing,
            'claim_token' => (string) Str::uuid(),
            'processing_started_at' => now(),
        ])->save();

        $this->expectException(OnboardingImportConflictException::class);
        $this->service()->start($salon, $user, 'url', 'http://1.1.1.1/');
    }

    public function test_different_url_while_review_required_supersedes_and_resets_salon(): void
    {
        Bus::fake();
        [$salon, $user] = $this->createSalonAndUser();

        $draft = $this->service()->start($salon, $user, 'url', 'http://93.184.216.34/');
        $draft->forceFill(['status' => OnboardingDraftStatus::Analysing, 'claim_token' => 'x', 'processing_started_at' => now()])->save();
        app(OnboardingDraftStateMachine::class)->transition($draft->refresh(), OnboardingDraftStatus::ReviewRequired);
        app(OnboardingStateMachine::class)->transition($salon->refresh(), OnboardingState::Analysing);
        app(OnboardingStateMachine::class)->transition($salon->refresh(), OnboardingState::ReviewRequired);

        $newDraft = $this->service()->start($salon->refresh(), $user, 'url', 'http://1.1.1.1/');

        $this->assertNotSame($draft->id, $newDraft->id);
        $this->assertSame(OnboardingDraftStatus::Superseded, $draft->refresh()->status);
        $this->assertNotNull($draft->superseded_at);
        // Bus::fake() prevents the job from actually running, so the salon is left at
        // source_pending here — the new draft's (faked) job would drive it onward to
        // analysing once it actually runs.
        $this->assertSame(OnboardingState::SourcePending, $salon->refresh()->onboarding_state);
    }

    public function test_start_rejected_after_identity_ready(): void
    {
        [$salon, $user] = $this->createSalonAndUser();
        $salon->forceFill(['onboarding_state' => OnboardingState::IdentityReady])->save();

        $this->expectException(OnboardingImportLockedException::class);
        $this->service()->start($salon, $user, 'url', 'http://93.184.216.34/');

        $this->assertSame(0, OnboardingDraft::query()->count());
    }

    public function test_only_one_active_draft_per_salon_at_the_db_level(): void
    {
        [$salon, $user] = $this->createSalonAndUser();

        OnboardingDraft::query()->create([
            'salon_id' => $salon->id,
            'created_by_user_id' => $user->id,
            'source_type' => 'url',
            'source_url' => 'https://a.example.com',
            'normalized_source_url' => 'https://a.example.com',
            'status' => OnboardingDraftStatus::Pending,
        ]);

        $this->expectException(QueryException::class);

        OnboardingDraft::query()->create([
            'salon_id' => $salon->id,
            'created_by_user_id' => $user->id,
            'source_type' => 'url',
            'source_url' => 'https://b.example.com',
            'normalized_source_url' => 'https://b.example.com',
            'status' => OnboardingDraftStatus::Pending,
        ]);
    }

    private function service(): OnboardingImportService
    {
        return $this->app->make(OnboardingImportService::class);
    }

    /**
     * @return array{0: Salon, 1: User}
     */
    private function createSalonAndUser(): array
    {
        $user = User::factory()->create();
        /** @var Salon $salon */
        $salon = $user->salon()->create(['name' => 'Test Salon']);

        return [$salon, $user];
    }
}
