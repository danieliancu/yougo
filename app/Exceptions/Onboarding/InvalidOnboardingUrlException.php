<?php

namespace App\Exceptions\Onboarding;

use RuntimeException;

class InvalidOnboardingUrlException extends RuntimeException
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
