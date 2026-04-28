<?php

namespace App\Services\Booking;

use App\Models\Booking;
use App\Models\Location;
use App\Models\Salon;
use App\Models\Service;
use App\Models\Staff;
use Illuminate\Support\Carbon;
use Throwable;

class AvailabilityChecker
{
    private const DEFAULT_DURATION_MINUTES = 30;

    public function check(Salon $salon, int $locationId, int $serviceId, string $dateStr, string $timeStr, ?int $staffId = null): array
    {
        $location = $salon->locations()->whereKey($locationId)->first();
        abort_unless($location, 422, 'Locatia nu apartine salonului.');

        $service = $salon->services()->whereKey($serviceId)->first();
        abort_unless($service, 422, 'Serviciul nu apartine salonului.');

        $this->checkServiceLocation($service, $locationId, $location);
        $staff = $this->checkStaff($salon, $service, $locationId, $staffId);
        $date = $this->parseDate($dateStr);
        $start = $this->parseTime($date, $timeStr);
        $duration = $this->serviceDuration($service);
        $end = $start->copy()->addMinutes($duration);

        $this->checkLocationHours($location, $date, $start, $end);
        $this->checkLocationCapacity($salon, $location, $dateStr, $start, $end);
        $this->checkServiceCapacity($salon, $service, $locationId, $dateStr, $start, $end);

        if ($staff) {
            $this->checkStaffHours($staff, $date, $start, $end);
            $this->checkOverlappingStaffBookings($salon, $staff, $dateStr, $start, $end);
        }

        return [$location, $service, $date, $staff];
    }

    private function checkServiceLocation(Service $service, int $locationId, Location $location): void
    {
        $locationIds = $service->location_ids ?? [];

        if (! empty($locationIds)) {
            abort_unless(in_array($locationId, $locationIds), 422, "Serviciul {$service->name} nu este disponibil la locatia {$location->name}.");
        }
    }

    private function parseDate(string $dateStr): Carbon
    {
        try {
            $date = Carbon::createFromFormat('Y-m-d', $dateStr);
        } catch (Throwable) {
            $date = null;
        }

        abort_unless($date && $date->format('Y-m-d') === $dateStr, 422, 'Data programarii este invalida.');
        abort_unless($date->startOfDay()->gte(now()->startOfDay()), 422, 'Nu se pot face programari in trecut.');

        return $date;
    }

    private function checkStaff(Salon $salon, Service $service, int $locationId, ?int $staffId): ?Staff
    {
        if (! $staffId) {
            return null;
        }

        $staff = $salon->staff()->whereKey($staffId)->first();
        abort_unless($staff, 422, 'Staff-ul selectat nu apartine salonului.');
        abort_unless($staff->active, 422, "Staff-ul {$staff->name} nu este activ.");

        abort_unless(
            $service->staffMembers()->whereKey($staff->id)->exists(),
            422,
            "Staff-ul {$staff->name} nu este alocat serviciului {$service->name}."
        );

        $staffLocationIds = $staff->locations()->pluck('locations.id')->map(fn ($id) => (int) $id)->all();
        if (count($staffLocationIds) > 0) {
            abort_unless(in_array($locationId, $staffLocationIds, true), 422, "Staff-ul {$staff->name} nu lucreaza la locatia selectata.");
        } elseif ($staff->location_id !== null) {
            abort_unless((int) $staff->location_id === $locationId, 422, "Staff-ul {$staff->name} nu lucreaza la locatia selectata.");
        }

        return $staff;
    }

    private function parseTime(Carbon $date, string $timeStr): Carbon
    {
        abort_unless(preg_match('/^\d{2}:\d{2}$/', $timeStr), 422, 'Formatul orei este invalid. Foloseste HH:MM.');

        [$hour, $minute] = array_map('intval', explode(':', $timeStr));
        abort_unless($hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59, 422, 'Ora programarii este invalida.');

        return $date->copy()->setTime($hour, $minute);
    }

    private function checkLocationHours(Location $location, Carbon $date, Carbon $start, Carbon $end): void
    {
        $dayKey = strtolower($date->format('D'));
        $hours = $location->hours ?? [];
        $dayHours = $hours[$dayKey] ?? null;

        if (! $dayHours) {
            return;
        }

        if (stripos($dayHours, 'inchis') !== false || stripos($dayHours, 'closed') !== false) {
            abort(422, "Locatia {$location->name} este inchisa in ziua selectata.");
        }

        [$opensAt, $closesAt] = $this->parseLocationHours($location, $date, (string) $dayHours);

        abort_unless(
            $start->gte($opensAt) && $start->lt($closesAt),
            422,
            "Ora {$start->format('H:i')} este in afara programului locatiei ({$dayHours})."
        );

        abort_unless(
            $end->lte($closesAt),
            422,
            "Programarea se termina la {$end->format('H:i')}, dupa ora de inchidere a locatiei ({$closesAt->format('H:i')})."
        );
    }

    private function checkStaffHours(Staff $staff, Carbon $date, Carbon $start, Carbon $end): void
    {
        $dayKey = strtolower($date->format('D'));
        $hours = $staff->working_hours ?? [];
        $dayHours = $hours[$dayKey] ?? null;

        if (! $dayHours) {
            return;
        }

        if (stripos($dayHours, 'inchis') !== false || stripos($dayHours, 'closed') !== false) {
            abort(422, "Staff-ul {$staff->name} nu lucreaza in ziua selectata.");
        }

        $parsed = $this->parseHours($date, (string) $dayHours);
        if (! $parsed) {
            return;
        }

        [$startsAt, $endsAt] = $parsed;

        abort_unless(
            $start->gte($startsAt) && $start->lt($endsAt),
            422,
            "Ora {$start->format('H:i')} este in afara programului staff-ului {$staff->name} ({$dayHours})."
        );

        abort_unless(
            $end->lte($endsAt),
            422,
            "Programarea se termina la {$end->format('H:i')}, dupa programul staff-ului {$staff->name} ({$endsAt->format('H:i')})."
        );
    }

    private function parseLocationHours(Location $location, Carbon $date, string $dayHours): array
    {
        $normalized = str_replace(['â€“', 'â€”', '–', '—'], '-', trim($dayHours));

        abort_unless(
            preg_match('/^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/', $normalized, $m),
            422,
            "Programul locatiei {$location->name} este configurat invalid ({$dayHours}). Foloseste formatul HH:MM - HH:MM sau Inchis."
        );

        [$openHour, $openMinute, $closeHour, $closeMinute] = [
            (int) $m[1],
            (int) $m[2],
            (int) $m[3],
            (int) $m[4],
        ];

        abort_unless(
            $this->validTimeParts($openHour, $openMinute) && $this->validTimeParts($closeHour, $closeMinute),
            422,
            "Programul locatiei {$location->name} este configurat invalid ({$dayHours}). Orele trebuie sa fie intre 00:00 si 23:59."
        );

        $opensAt = $date->copy()->setTime($openHour, $openMinute);
        $closesAt = $date->copy()->setTime($closeHour, $closeMinute);

        abort_unless(
            $opensAt->lt($closesAt),
            422,
            "Programul locatiei {$location->name} este configurat invalid ({$dayHours}). Ora de inchidere trebuie sa fie dupa ora de deschidere."
        );

        return [$opensAt, $closesAt];
    }

    private function parseHours(Carbon $date, string $dayHours): ?array
    {
        $normalized = str_replace(['â€“', 'â€”', '–', '—'], '-', trim($dayHours));

        if (! preg_match('/^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/', $normalized, $m)) {
            return null;
        }

        [$openHour, $openMinute, $closeHour, $closeMinute] = [
            (int) $m[1],
            (int) $m[2],
            (int) $m[3],
            (int) $m[4],
        ];

        if (! $this->validTimeParts($openHour, $openMinute) || ! $this->validTimeParts($closeHour, $closeMinute)) {
            return null;
        }

        $opensAt = $date->copy()->setTime($openHour, $openMinute);
        $closesAt = $date->copy()->setTime($closeHour, $closeMinute);

        if (! $opensAt->lt($closesAt)) {
            return null;
        }

        return [$opensAt, $closesAt];
    }

    private function validTimeParts(int $hour, int $minute): bool
    {
        return $hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59;
    }

    private function checkOverlappingBookings(Salon $salon, int $locationId, string $dateStr, Carbon $start, Carbon $end): void
    {
        $bookings = $salon->bookings()
            ->with('service')
            ->where('location_id', $locationId)
            ->whereDate('date', $dateStr)
            ->whereIn('status', ['pending', 'confirmed'])
            ->get();

        foreach ($bookings as $booking) {
            $existingStart = $this->parseTime($start, $booking->time);
            $existingEnd = $existingStart->copy()->addMinutes($this->bookingDuration($booking));

            if ($start->lt($existingEnd) && $end->gt($existingStart)) {
                abort(422, "Intervalul {$start->format('H:i')} - {$end->format('H:i')} se suprapune cu o programare existenta.");
            }
        }
    }

    private function checkLocationCapacity(Salon $salon, Location $location, string $dateStr, Carbon $start, Carbon $end): void
    {
        $capacity = $location->max_concurrent_bookings ?: 1;
        $overlapping = $this->overlappingBookings($salon, $dateStr, $start, $end)
            ->where('location_id', $location->id)
            ->count();

        abort_unless($overlapping < $capacity, 422, 'Locatia este complet ocupata in intervalul selectat.');
    }

    private function checkServiceCapacity(Salon $salon, Service $service, int $locationId, string $dateStr, Carbon $start, Carbon $end): void
    {
        $capacity = $service->max_concurrent_bookings ?: 1;
        $overlapping = $this->overlappingBookings($salon, $dateStr, $start, $end)
            ->where('location_id', $locationId)
            ->where('service_id', $service->id)
            ->count();

        abort_unless($overlapping < $capacity, 422, 'Serviciul este complet ocupat in intervalul selectat.');
    }

    private function overlappingBookings(Salon $salon, string $dateStr, Carbon $start, Carbon $end)
    {
        return $salon->bookings()
            ->with('service')
            ->whereDate('date', $dateStr)
            ->whereIn('status', ['pending', 'confirmed'])
            ->get()
            ->filter(function (Booking $booking) use ($start, $end) {
                $existingStart = $this->parseTime($start, $booking->time);
                $existingEnd = $existingStart->copy()->addMinutes($this->bookingDuration($booking));

                return $start->lt($existingEnd) && $end->gt($existingStart);
            });
    }

    private function checkOverlappingStaffBookings(Salon $salon, Staff $staff, string $dateStr, Carbon $start, Carbon $end): void
    {
        $bookings = $salon->bookings()
            ->with('service')
            ->where('staff_id', $staff->id)
            ->whereDate('date', $dateStr)
            ->whereIn('status', ['pending', 'confirmed'])
            ->get();

        foreach ($bookings as $booking) {
            $existingStart = $this->parseTime($start, $booking->time);
            $existingEnd = $existingStart->copy()->addMinutes($this->bookingDuration($booking));

            if ($start->lt($existingEnd) && $end->gt($existingStart)) {
                abort(422, "Staff-ul {$staff->name} are deja o programare in intervalul selectat.");
            }
        }
    }

    private function serviceDuration(Service $service): int
    {
        return $service->duration > 0 ? $service->duration : self::DEFAULT_DURATION_MINUTES;
    }

    private function bookingDuration(Booking $booking): int
    {
        $duration = $booking->service?->duration;

        return $duration && $duration > 0 ? $duration : self::DEFAULT_DURATION_MINUTES;
    }
}
