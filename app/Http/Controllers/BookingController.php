<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Location;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BookingController extends Controller
{
    public function update(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorizeOwner($request, $booking);

        $data = $request->validate([
            'location_id' => ['sometimes', 'nullable', 'integer'],
            'service_id' => ['sometimes', 'nullable', 'integer'],
            'client_name' => ['sometimes', 'required', 'string', 'max:255'],
            'client_phone' => ['sometimes', 'nullable', 'string', 'max:255'],
            'staff' => ['sometimes', 'nullable', 'array'],
            'staff.*' => ['nullable', 'string', 'max:255'],
            'date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'time' => ['sometimes', 'required', 'date_format:H:i'],
            'status' => ['sometimes', 'required', Rule::in(Booking::STATUSES)],
        ]);

        $this->validateLocation($request, $data['location_id'] ?? null);
        $this->validateService($request, $data['service_id'] ?? null);

        if (array_key_exists('staff', $data)) {
            $data['staff'] = collect($data['staff'] ?? [])
                ->map(fn ($member) => trim((string) $member))
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        $booking->update($data);

        return back()->with('success', 'Status actualizat.');
    }

    public function destroy(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorizeOwner($request, $booking);
        $booking->delete();

        return back()->with('success', 'Programare stearsa.');
    }

    private function authorizeOwner(Request $request, Booking $booking): void
    {
        abort_unless($booking->salon_id === $request->user()->salon?->id, 403);
    }

    private function validateLocation(Request $request, ?int $locationId): void
    {
        if ($locationId === null) {
            return;
        }

        abort_unless(
            Location::query()
                ->where('salon_id', $request->user()->salon?->id)
                ->whereKey($locationId)
                ->exists(),
            422,
            'Locatie invalida.'
        );
    }

    private function validateService(Request $request, ?int $serviceId): void
    {
        if ($serviceId === null) {
            return;
        }

        abort_unless(
            Service::query()
                ->where('salon_id', $request->user()->salon?->id)
                ->whereKey($serviceId)
                ->exists(),
            422,
            'Serviciu invalid.'
        );
    }
}
