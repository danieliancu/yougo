<?php

namespace App\Services\Booking;

use App\Models\Booking;
use App\Models\Salon;
use Illuminate\Support\Arr;

class BookingCreator
{
    public function __construct(private readonly AvailabilityChecker $availabilityChecker)
    {
    }

    public function createFromAiFunctionCall(Salon $salon, array $args): Booking
    {
        $locationId = (int) Arr::get($args, 'location_id');
        $serviceId = (int) Arr::get($args, 'service_id');
        $dateStr = (string) Arr::get($args, 'date');
        $timeStr = $this->normalizeAiTime((string) Arr::get($args, 'time'));
        $staffId = Arr::has($args, 'staff_id') ? (int) Arr::get($args, 'staff_id') : null;

        [, , , $staff] = $this->availabilityChecker->check($salon, $locationId, $serviceId, $dateStr, $timeStr, $staffId);

        return $salon->bookings()->create([
            'location_id' => $locationId,
            'service_id' => $serviceId,
            'client_name' => (string) Arr::get($args, 'client_name'),
            'client_phone' => (string) Arr::get($args, 'client_phone', ''),
            'staff' => $staff ? [$staff->name] : collect(Arr::wrap(Arr::get($args, 'staff', [])))->filter()->values()->all(),
            'date' => $dateStr,
            'time' => $timeStr,
            'status' => 'pending',
        ]);
    }

    private function normalizeAiTime(string $time): string
    {
        $time = trim(strtolower($time));
        $time = str_replace(['ă', 'â', 'î', 'ș', 'ş', 'ț', 'ţ'], ['a', 'a', 'i', 's', 's', 't', 't'], $time);
        $time = trim($time, " \t\n\r\0\x0B.,;)");

        if (preg_match('/^\d{1,2}$/', $time)) {
            return $this->formatNormalizedTime((int) $time, 0) ?? $time;
        }

        if (preg_match('/^(\d{1,2})[.:](\d{2})(?::\d{2})?$/', $time, $matches)) {
            return $this->formatNormalizedTime((int) $matches[1], (int) $matches[2]) ?? $time;
        }

        if (preg_match('/\b(\d{1,2})\s*h(?:\s*(\d{2}))?\b/', $time, $matches)) {
            $normalized = $this->formatNormalizedTime((int) $matches[1], (int) ($matches[2] ?? 0));
            if ($normalized) {
                return $normalized;
            }
        }

        if (preg_match('/\b(\d{1,2})[.:](\d{2})(?::\d{2})?\b/', $time, $matches)) {
            $normalized = $this->formatNormalizedTime((int) $matches[1], (int) $matches[2]);
            if ($normalized) {
                return $normalized;
            }
        }

        if (preg_match('/\b(?:ora\s+)?(\d{1,2})\s*(?:si|s[iî])\s*(?:un\s+)?sfert\b/u', $time, $matches)) {
            $normalized = $this->formatNormalizedTime((int) $matches[1], 15);
            if ($normalized) {
                return $normalized;
            }
        }

        if (preg_match('/\b(?:ora\s+)?(\d{1,2})\s*(?:si|s[iî])\s*(?:jumatate|jumate)\b/u', $time, $matches)) {
            $normalized = $this->formatNormalizedTime((int) $matches[1], 30);
            if ($normalized) {
                return $normalized;
            }
        }

        if (preg_match('/\b(?:ora\s+)?(\d{1,2})\s*(?:si|s[iî])\s*(\d{1,2})\b/u', $time, $matches)) {
            $normalized = $this->formatNormalizedTime((int) $matches[1], (int) $matches[2]);
            if ($normalized) {
                return $normalized;
            }
        }

        if (preg_match('/\b(?:ora\s+)?(\d{1,2})\s*fara\s+(?:un\s+)?sfert\b/u', $time, $matches)) {
            $hour = ((int) $matches[1]) - 1;
            if ($hour < 0) {
                $hour = 23;
            }

            return $this->formatNormalizedTime($hour, 45) ?? $time;
        }

        if (preg_match('/\bora\s+(\d{1,2})\b/', $time, $matches)) {
            return $this->formatNormalizedTime((int) $matches[1], 0) ?? $time;
        }

        return $time;
    }

    private function formatNormalizedTime(int $hour, int $minute): ?string
    {
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            return null;
        }

        return str_pad((string) $hour, 2, '0', STR_PAD_LEFT).':'.str_pad((string) $minute, 2, '0', STR_PAD_LEFT);
    }
}
