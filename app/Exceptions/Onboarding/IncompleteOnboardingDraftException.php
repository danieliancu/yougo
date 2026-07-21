<?php

namespace App\Exceptions\Onboarding;

use RuntimeException;

/**
 * Thrown when a confirm request's resolved data would not meet the minimum
 * completeness requirements for identity_ready. Nothing is persisted when this is
 * thrown — maps to HTTP 422 at the controller boundary.
 */
class IncompleteOnboardingDraftException extends RuntimeException
{
    /**
     * @param  list<string>  $failedConditions
     */
    public function __construct(public readonly array $failedConditions)
    {
        parent::__construct('This draft does not yet meet the minimum requirements to activate the business identity.');
    }
}
