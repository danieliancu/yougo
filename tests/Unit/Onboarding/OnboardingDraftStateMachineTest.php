<?php

namespace Tests\Unit\Onboarding;

use App\Enums\OnboardingDraftStatus;
use App\Exceptions\Onboarding\InvalidOnboardingDraftTransitionException;
use App\Models\OnboardingDraft;
use App\Models\Salon;
use App\Models\User;
use App\Services\Onboarding\OnboardingDraftStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingDraftStateMachineTest extends TestCase
{
    use RefreshDatabase;

    public function test_allows_documented_transitions(): void
    {
        $machine = new OnboardingDraftStateMachine;

        $this->assertTrue($machine->canTransition(OnboardingDraftStatus::Pending, OnboardingDraftStatus::Analysing));
        $this->assertTrue($machine->canTransition(OnboardingDraftStatus::Pending, OnboardingDraftStatus::Superseded));
        $this->assertTrue($machine->canTransition(OnboardingDraftStatus::Analysing, OnboardingDraftStatus::ReviewRequired));
        $this->assertTrue($machine->canTransition(OnboardingDraftStatus::Analysing, OnboardingDraftStatus::AnalysisFailed));
        $this->assertTrue($machine->canTransition(OnboardingDraftStatus::Analysing, OnboardingDraftStatus::Superseded));
        $this->assertTrue($machine->canTransition(OnboardingDraftStatus::ReviewRequired, OnboardingDraftStatus::Confirmed));
        $this->assertTrue($machine->canTransition(OnboardingDraftStatus::ReviewRequired, OnboardingDraftStatus::Superseded));
        $this->assertTrue($machine->canTransition(OnboardingDraftStatus::AnalysisFailed, OnboardingDraftStatus::Analysing));
        $this->assertTrue($machine->canTransition(OnboardingDraftStatus::AnalysisFailed, OnboardingDraftStatus::Superseded));
    }

    public function test_rejects_undocumented_transitions(): void
    {
        $machine = new OnboardingDraftStateMachine;

        $this->assertFalse($machine->canTransition(OnboardingDraftStatus::Pending, OnboardingDraftStatus::ReviewRequired));
        $this->assertFalse($machine->canTransition(OnboardingDraftStatus::Confirmed, OnboardingDraftStatus::Analysing));
        $this->assertFalse($machine->canTransition(OnboardingDraftStatus::Superseded, OnboardingDraftStatus::Pending));
        $this->assertFalse($machine->canTransition(OnboardingDraftStatus::ReviewRequired, OnboardingDraftStatus::Analysing));
    }

    public function test_transition_persists_and_throws(): void
    {
        $draft = $this->createDraft();
        $machine = new OnboardingDraftStateMachine;

        $machine->transition($draft, OnboardingDraftStatus::Analysing);
        $this->assertSame(OnboardingDraftStatus::Analysing, $draft->refresh()->status);

        $this->expectException(InvalidOnboardingDraftTransitionException::class);
        $machine->transition($draft, OnboardingDraftStatus::Confirmed);
    }

    private function createDraft(): OnboardingDraft
    {
        $user = User::factory()->create();
        /** @var Salon $salon */
        $salon = $user->salon()->create(['name' => 'Test Salon']);

        return OnboardingDraft::query()->create([
            'salon_id' => $salon->id,
            'created_by_user_id' => $user->id,
            'source_type' => 'url',
            'source_url' => 'https://example.com',
            'normalized_source_url' => 'https://example.com',
            'status' => OnboardingDraftStatus::Pending,
        ]);
    }
}
