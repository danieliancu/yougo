<?php

namespace App\Enums;

enum OnboardingDraftStatus: string
{
    case Pending = 'pending';
    case Analysing = 'analysing';
    case ReviewRequired = 'review_required';
    case AnalysisFailed = 'analysis_failed';
    case Confirmed = 'confirmed';
    case Superseded = 'superseded';

    /**
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return [
            self::Pending->value,
            self::Analysing->value,
            self::ReviewRequired->value,
            self::AnalysisFailed->value,
        ];
    }

    public function isActive(): bool
    {
        return in_array($this->value, self::activeValues(), true);
    }
}
