<?php

namespace App\Services\Onboarding;

use App\Enums\OnboardingDraftStatus;
use App\Exceptions\Onboarding\InvalidOnboardingDraftTransitionException;
use App\Models\OnboardingDraft;

/**
 * The only place allowed to write OnboardingDraft::status. Callers must already hold
 * whatever DB transaction/lock is appropriate — this class does not open its own.
 */
class OnboardingDraftStateMachine
{
    /**
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        'pending' => ['analysing', 'superseded'],
        'analysing' => ['review_required', 'analysis_failed', 'superseded'],
        'review_required' => ['confirmed', 'superseded'],
        'analysis_failed' => ['analysing', 'superseded'],
        'confirmed' => [],
        'superseded' => [],
    ];

    public function canTransition(OnboardingDraftStatus $from, OnboardingDraftStatus $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return in_array($to->value, self::TRANSITIONS[$from->value] ?? [], true);
    }

    public function transition(OnboardingDraft $draft, OnboardingDraftStatus $to): void
    {
        $from = $draft->status;

        if (! $this->canTransition($from, $to)) {
            throw new InvalidOnboardingDraftTransitionException(
                "Cannot transition onboarding draft status from [{$from->value}] to [{$to->value}]."
            );
        }

        if ($from === $to) {
            return;
        }

        $draft->forceFill(['status' => $to])->save();
    }
}
