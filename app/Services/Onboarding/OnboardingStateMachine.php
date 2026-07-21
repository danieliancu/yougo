<?php

namespace App\Services\Onboarding;

use App\Enums\OnboardingState;
use App\Exceptions\Onboarding\InvalidOnboardingStateTransitionException;
use App\Models\Salon;

/**
 * The only place allowed to write Salon::onboarding_state. Callers must already hold
 * whatever DB transaction/lock is appropriate — this class does not open its own.
 *
 * The three "-> source_pending" transitions (from analysing, analysis_failed, and
 * review_required) are structurally reserved for draft supersession: they must only
 * ever be invoked from OnboardingImportService::supersedeActiveDraft(), never from the
 * job or the confirmation service directly. This class only validates that a move is
 * in the table below; it has no notion of "caller", so this restriction is a code
 * convention, not a runtime check.
 */
class OnboardingStateMachine
{
    /**
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        'source_pending' => ['analysing'],
        'analysing' => ['review_required', 'analysis_failed', 'source_pending'],
        'analysis_failed' => ['analysing', 'source_pending'],
        'review_required' => ['identity_ready', 'source_pending'],
        'identity_ready' => ['voice_ready'],
        'voice_ready' => ['phone_pending'],
        'phone_pending' => ['phone_ready'],
        'phone_ready' => ['test_pending'],
        'test_pending' => ['test_completed'],
        'test_completed' => ['ready_to_activate'],
        'ready_to_activate' => ['active'],
        'active' => [],
    ];

    public function canTransition(OnboardingState $from, OnboardingState $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return in_array($to->value, self::TRANSITIONS[$from->value] ?? [], true);
    }

    public function transition(Salon $salon, OnboardingState $to): void
    {
        $from = $salon->onboarding_state;

        if (! $this->canTransition($from, $to)) {
            throw new InvalidOnboardingStateTransitionException(
                "Cannot transition salon onboarding state from [{$from->value}] to [{$to->value}]."
            );
        }

        if ($from === $to) {
            return;
        }

        $salon->forceFill(['onboarding_state' => $to])->save();
    }
}
