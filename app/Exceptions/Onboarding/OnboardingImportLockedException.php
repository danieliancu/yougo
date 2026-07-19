<?php

namespace App\Exceptions\Onboarding;

use RuntimeException;

/**
 * Thrown when starting a new import is rejected because the salon has already reached
 * identity_ready (or later) — maps to HTTP 409 at the controller boundary.
 */
class OnboardingImportLockedException extends RuntimeException {}
