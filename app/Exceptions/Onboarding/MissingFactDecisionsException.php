<?php

namespace App\Exceptions\Onboarding;

use RuntimeException;

/**
 * Thrown when a confirm request doesn't include an explicit decision for every fact
 * marked requires_confirmation. Nothing is persisted when this is thrown — maps to
 * HTTP 422 at the controller boundary.
 */
class MissingFactDecisionsException extends RuntimeException
{
    /**
     * @param  list<string>  $missingPaths
     */
    public function __construct(public readonly array $missingPaths)
    {
        parent::__construct('Some imported facts require an explicit decision before this draft can be confirmed.');
    }
}
