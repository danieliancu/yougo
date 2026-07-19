<?php

namespace App\Exceptions\Onboarding;

use RuntimeException;

/**
 * Thrown when a draft update/confirm request's expected_revision does not match the
 * draft's current revision — maps to HTTP 409 at the controller boundary.
 */
class OnboardingRevisionConflictException extends RuntimeException
{
    public function __construct(public readonly int $currentRevision)
    {
        parent::__construct('The draft has changed since you last loaded it.');
    }
}
