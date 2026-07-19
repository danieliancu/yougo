<?php

namespace App\Exceptions\Onboarding;

use RuntimeException;

class InvalidExtractionResultException extends RuntimeException
{
    /**
     * @param  array<int, string>  $errors
     */
    public function __construct(string $message, private readonly array $errors = [])
    {
        parent::__construct($message);
    }

    /**
     * @return array<int, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
