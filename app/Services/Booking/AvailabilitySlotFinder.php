<?php

namespace App\Services\Booking;

use App\Models\Salon;
use Illuminate\Support\Carbon;
use Throwable;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AvailabilitySlotFinder
{
    private const DEFAULT_START = '09:00';
    private const DEFAULT_END = '17:00';

    public function __construct(private readonly AvailabilityChecker $availabilityChecker)
    {
    }

    public function find(Salon $salon, int $locationId, int $serviceId, string $dateStr, ?int $staffId = null, int $limit = 5): array
    {
        $location = $salon->locations()->whereKey($locationId)->first();
        $service = $salon->services()->whereKey($serviceId)->first();

        if (! $location || ! $service) {
            return [];
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $dateStr);
        } catch (Throwable) {
            return [];
        }

        if (! $date || $date->format('Y-m-d') !== $dateStr) {
            return [];
        }

        $window = $this->candidateWindow($location->hours ?? [], $date);
        if (! $window) {
            return [];
        }

        [$startTime, $endTime] = $window;
        $duration = $service->duration > 0 ? $service->duration : 30;
        $step = $duration <= 15 ? 15 : 30;
        $cursor = $date->copy()->setTimeFromTimeString($startTime);
        $end = $date->copy()->setTimeFromTimeString($endTime);
        $slots = [];

        while ($cursor->copy()->addMinutes($duration)->lte($end) && count($slots) < $limit) {
            $time = $cursor->format('H:i');

            try {
                $this->availabilityChecker->check($salon, $locationId, $serviceId, $dateStr, $time, $staffId);
                $slots[] = $time;
            } catch (HttpException) {
                //
            }

            $cursor->addMinutes($step);
        }

        return $slots;
    }

    private function candidateWindow(array $hours, Carbon $date): ?array
    {
        $dayKey = strtolower($date->format('D'));
        $dayHours = trim((string) ($hours[$dayKey] ?? ''));

        if ($dayHours === '') {
            return [self::DEFAULT_START, self::DEFAULT_END];
        }

        if (stripos($dayHours, 'inchis') !== false || stripos($dayHours, 'closed') !== false) {
            return null;
        }

        $normalized = str_replace(['â€“', 'â€”', '–', '—'], '-', $dayHours);
        if (! preg_match('/^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/', $normalized, $m)) {
            return [self::DEFAULT_START, self::DEFAULT_END];
        }

        return [
            sprintf('%02d:%02d', (int) $m[1], (int) $m[2]),
            sprintf('%02d:%02d', (int) $m[3], (int) $m[4]),
        ];
    }
}
