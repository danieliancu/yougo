<?php

namespace App\Exceptions\Onboarding;

use RuntimeException;

/**
 * Thrown for any onboarding-import concurrency conflict (an import already in
 * progress, a draft that can't be retried/updated/confirmed in its current state) —
 * maps to HTTP 409 at the controller boundary.
 */
class OnboardingImportConflictException extends RuntimeException
{
    public function __construct(string $message, private readonly string $reasonCode)
    {
        parent::__construct($message);
    }

    public function reasonCode(): string
    {
        return $this->reasonCode;
    }
}
