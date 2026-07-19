<?php

namespace Tests\Unit\Onboarding;

use App\Enums\OnboardingState;
use App\Exceptions\Onboarding\InvalidOnboardingStateTransitionException;
use App\Models\Salon;
use App\Models\User;
use App\Services\Onboarding\OnboardingStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingStateMachineTest extends TestCase
{
    use RefreshDatabase;

    public function test_allows_documented_transitions(): void
    {
        $machine = new OnboardingStateMachine;

        $this->assertTrue($machine->canTransition(OnboardingState::SourcePending, OnboardingState::Analysing));
        $this->assertTrue($machine->canTransition(OnboardingState::Analysing, OnboardingState::ReviewRequired));
        $this->assertTrue($machine->canTransition(OnboardingState::Analysing, OnboardingState::AnalysisFailed));
        $this->assertTrue($machine->canTransition(OnboardingState::Analysing, OnboardingState::SourcePending));
        $this->assertTrue($machine->canTransition(OnboardingState::AnalysisFailed, OnboardingState::Analysing));
        $this->assertTrue($machine->canTransition(OnboardingState::AnalysisFailed, OnboardingState::SourcePending));
        $this->assertTrue($machine->canTransition(OnboardingState::ReviewRequired, OnboardingState::IdentityReady));
        $this->assertTrue($machine->canTransition(OnboardingState::ReviewRequired, OnboardingState::SourcePending));
        $this->assertTrue($machine->canTransition(OnboardingState::IdentityReady, OnboardingState::VoiceReady));
        $this->assertTrue($machine->canTransition(OnboardingState::VoiceReady, OnboardingState::PhonePending));
        $this->assertTrue($machine->canTransition(OnboardingState::PhonePending, OnboardingState::PhoneReady));
        $this->assertTrue($machine->canTransition(OnboardingState::PhoneReady, OnboardingState::TestPending));
        $this->assertTrue($machine->canTransition(OnboardingState::TestPending, OnboardingState::TestCompleted));
        $this->assertTrue($machine->canTransition(OnboardingState::TestCompleted, OnboardingState::ReadyToActivate));
        $this->assertTrue($machine->canTransition(OnboardingState::ReadyToActivate, OnboardingState::Active));
    }

    public function test_allows_same_state_no_op(): void
    {
        $machine = new OnboardingStateMachine;

        foreach (OnboardingState::cases() as $state) {
            $this->assertTrue($machine->canTransition($state, $state));
        }
    }

    public function test_rejects_undocumented_transitions(): void
    {
        $machine = new OnboardingStateMachine;

        $this->assertFalse($machine->canTransition(OnboardingState::SourcePending, OnboardingState::ReviewRequired));
        $this->assertFalse($machine->canTransition(OnboardingState::SourcePending, OnboardingState::IdentityReady));
        $this->assertFalse($machine->canTransition(OnboardingState::IdentityReady, OnboardingState::SourcePending));
        $this->assertFalse($machine->canTransition(OnboardingState::Active, OnboardingState::SourcePending));
        $this->assertFalse($machine->canTransition(OnboardingState::PhonePending, OnboardingState::TestCompleted));
    }

    public function test_transition_persists_new_state(): void
    {
        $salon = $this->createSalon();
        $machine = new OnboardingStateMachine;

        $machine->transition($salon, OnboardingState::Analysing);

        $this->assertSame(OnboardingState::Analysing, $salon->refresh()->onboarding_state);
    }

    public function test_transition_throws_on_invalid_move(): void
    {
        $salon = $this->createSalon();
        $machine = new OnboardingStateMachine;

        $this->expectException(InvalidOnboardingStateTransitionException::class);

        $machine->transition($salon, OnboardingState::IdentityReady);
    }

    public function test_locks_new_import_per_case(): void
    {
        $locked = [
            OnboardingState::IdentityReady,
            OnboardingState::VoiceReady,
            OnboardingState::PhonePending,
            OnboardingState::PhoneReady,
            OnboardingState::TestPending,
            OnboardingState::TestCompleted,
            OnboardingState::ReadyToActivate,
            OnboardingState::Active,
        ];

        $unlocked = [
            OnboardingState::SourcePending,
            OnboardingState::Analysing,
            OnboardingState::AnalysisFailed,
            OnboardingState::ReviewRequired,
        ];

        foreach ($locked as $state) {
            $this->assertTrue($state->locksNewImport(), "Expected {$state->value} to lock new imports.");
        }

        foreach ($unlocked as $state) {
            $this->assertFalse($state->locksNewImport(), "Expected {$state->value} to allow new imports.");
        }
    }

    private function createSalon(): Salon
    {
        $user = User::factory()->create();

        return $user->salon()->create(['name' => 'Test Salon']);
    }
}
