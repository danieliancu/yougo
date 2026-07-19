<?php

namespace App\Enums;

enum OnboardingState: string
{
    case SourcePending = 'source_pending';
    case Analysing = 'analysing';
    case AnalysisFailed = 'analysis_failed';
    case ReviewRequired = 'review_required';
    case IdentityReady = 'identity_ready';
    case VoiceReady = 'voice_ready';
    case PhonePending = 'phone_pending';
    case PhoneReady = 'phone_ready';
    case TestPending = 'test_pending';
    case TestCompleted = 'test_completed';
    case ReadyToActivate = 'ready_to_activate';
    case Active = 'active';

    /**
     * Whether a salon in this state is no longer allowed to start a new onboarding import.
     * Explicit case match so this stays correct regardless of the enum's declaration order.
     */
    public function locksNewImport(): bool
    {
        return match ($this) {
            self::IdentityReady,
            self::VoiceReady,
            self::PhonePending,
            self::PhoneReady,
            self::TestPending,
            self::TestCompleted,
            self::ReadyToActivate,
            self::Active => true,

            default => false,
        };
    }
}
