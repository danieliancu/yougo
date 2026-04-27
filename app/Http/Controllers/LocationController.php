<?php

namespace App\Http\Controllers;

use App\Models\Location;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LocationController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $salon = $request->user()->salon;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'hours' => ['nullable', 'array'],
        ]);

        $data['hours'] = $this->normalizeHours($data['hours'] ?? []);

        $salon->locations()->create($data);

        return back()->with('success', 'Locatie adaugata.');
    }

    public function update(Request $request, Location $location): RedirectResponse
    {
        $this->authorizeOwner($request, $location);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'hours' => ['nullable', 'array'],
        ]);

        $data['hours'] = $this->normalizeHours($data['hours'] ?? []);

        $location->update($data);

        return back()->with('success', 'Locatie actualizata.');
    }

    public function destroy(Request $request, Location $location): RedirectResponse
    {
        $this->authorizeOwner($request, $location);
        $location->delete();

        return back()->with('success', 'Locatie stearsa.');
    }

    private function authorizeOwner(Request $request, Location $location): void
    {
        abort_unless($location->salon_id === $request->user()->salon?->id, 403);
    }

    private function normalizeHours(array $hours): array
    {
        $normalized = [];
        $allowedDays = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

        foreach ($allowedDays as $day) {
            $value = trim((string) ($hours[$day] ?? ''));

            if ($value === '') {
                $normalized[$day] = '';
                continue;
            }

            if (preg_match('/^(inchis|closed)$/i', $value)) {
                $normalized[$day] = 'Inchis';
                continue;
            }

            $value = str_replace(['–', '—'], '-', $value);

            if (! preg_match('/^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/', $value, $matches)) {
                throw ValidationException::withMessages([
                    "hours.{$day}" => 'Program invalid. Foloseste formatul HH:MM - HH:MM sau Inchis.',
                ]);
            }

            [$openHour, $openMinute, $closeHour, $closeMinute] = [
                (int) $matches[1],
                (int) $matches[2],
                (int) $matches[3],
                (int) $matches[4],
            ];

            if (! $this->validTime($openHour, $openMinute) || ! $this->validTime($closeHour, $closeMinute)) {
                throw ValidationException::withMessages([
                    "hours.{$day}" => 'Program invalid. Orele trebuie sa fie intre 00:00 si 23:59.',
                ]);
            }

            $openTotal = $openHour * 60 + $openMinute;
            $closeTotal = $closeHour * 60 + $closeMinute;

            if ($openTotal >= $closeTotal) {
                throw ValidationException::withMessages([
                    "hours.{$day}" => 'Program invalid. Ora de inchidere trebuie sa fie dupa ora de deschidere.',
                ]);
            }

            $normalized[$day] = sprintf('%02d:%02d - %02d:%02d', $openHour, $openMinute, $closeHour, $closeMinute);
        }

        return $normalized;
    }

    private function validTime(int $hour, int $minute): bool
    {
        return $hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59;
    }
}
