<?php

namespace App\Services\Onboarding;

use App\Exceptions\Onboarding\InvalidExtractionResultException;

/**
 * Validates the day-keyed opening-hours structure used by both business.opening_hours
 * and location.opening_hours in the normalized extraction schema. Mirrors the format
 * rules already enforced manually in LocationController::normalizeHours (day keys,
 * "Inchis"/"closed" marker, "HH:MM - HH:MM" ranges) so the import path and the manual
 * location-editing path agree on what a valid schedule looks like — LocationController
 * itself is intentionally left untouched.
 */
class OnboardingHoursValidator
{
    public const ALLOWED_DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /**
     * @param  array<string, mixed>  $hours
     * @return array<string, string>
     */
    public function validate(array $hours): array
    {
        $normalized = [];

        foreach ($hours as $day => $value) {
            if (! is_string($day) || ! in_array($day, self::ALLOWED_DAYS, true)) {
                throw new InvalidExtractionResultException("Unknown opening_hours day key [{$day}].");
            }

            $value = trim((string) $value);

            if ($value === '') {
                $normalized[$day] = '';

                continue;
            }

            if (preg_match('/^(inchis|închis|closed)$/iu', $value)) {
                $normalized[$day] = 'Inchis';

                continue;
            }

            $value = str_replace(['–', '—'], '-', $value);

            // Minutes are optional on either side ("10-21:00", "10-21", "10:00-21") —
            // sites routinely write a bare hour when it's on the hour (real extraction:
            // "Luni - Vineri: 10-21:00"), and the AI copies that shorthand verbatim
            // rather than normalizing it. Always re-emitted zero-padded/normalized below
            // regardless of which shorthand was used on input.
            if (! preg_match('/^(\d{1,2})(?::(\d{2}))?\s*-\s*(\d{1,2})(?::(\d{2}))?$/', $value, $matches)) {
                throw new InvalidExtractionResultException("Invalid opening_hours value for [{$day}].");
            }

            [$openHour, $openMinute, $closeHour, $closeMinute] = [
                (int) $matches[1],
                (int) ($matches[2] ?? 0),
                (int) $matches[3],
                (int) ($matches[4] ?? 0),
            ];

            if (! $this->validTime($openHour, $openMinute) || ! $this->validTime($closeHour, $closeMinute)) {
                throw new InvalidExtractionResultException("Invalid opening_hours time range for [{$day}].");
            }

            $openTotal = $openHour * 60 + $openMinute;
            $closeTotal = $closeHour * 60 + $closeMinute;

            if ($openTotal >= $closeTotal) {
                throw new InvalidExtractionResultException("Invalid opening_hours ordering for [{$day}].");
            }

            $normalized[$day] = sprintf('%02d:%02d - %02d:%02d', $openHour, $openMinute, $closeHour, $closeMinute);
        }

        return $normalized;
    }

    /**
     * Whether the schedule has at least one day that isn't blank/closed —
     * used by the identity_ready completeness check (an all-closed/empty schedule
     * does not count as "opening hours present").
     *
     * @param  array<string, mixed>  $hours
     */
    public function hasAnyScheduledDay(array $hours): bool
    {
        foreach ($hours as $value) {
            if (is_string($value) && $value !== '' && strcasecmp($value, 'Inchis') !== 0) {
                return true;
            }
        }

        return false;
    }

    private function validTime(int $hour, int $minute): bool
    {
        return $hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59;
    }
}
