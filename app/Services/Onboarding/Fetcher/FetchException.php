<?php

namespace App\Services\Onboarding\Fetcher;

use RuntimeException;

/**
 * Safe reason codes only: source_unreachable, source_blocked, source_requires_authentication,
 * content_type_unsupported, fetch_limit_reached. Never carries raw response bodies/headers.
 */
class FetchException extends RuntimeException
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
