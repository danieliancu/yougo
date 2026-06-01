<?php

namespace App\Http\Controllers;

use App\Mail\BookingStatusChangedMail;
use App\Models\Booking;
use App\Models\Location;
use App\Models\Service;
use App\Services\Conversation\ConversationService;
use App\Support\BookingStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Throwable;

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

        $oldStatus = $booking->status;
        $booking->update($data);

        $newStatus = $data['status'] ?? null;
        if ($newStatus && $newStatus !== $oldStatus) {
            $this->sendStatusChangedEmail($booking, $oldStatus, $newStatus);
        }

        if ($newStatus && BookingStatus::isClosedForWhatsappAi($booking->refresh())) {
            app(ConversationService::class)->closeWhatsappConversationsForBooking($booking);
        }

        return back()->with('success', 'Status actualizat.');
    }

    public function destroy(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorizeOwner($request, $booking);
        $booking->delete();

        return back()->with('success', 'Programare stearsa.');
    }

    private function sendStatusChangedEmail(Booking $booking, string $oldStatus, string $newStatus): void
    {
        $booking->loadMissing(['salon', 'service', 'location', 'staffMember']);
        $salon = $booking->salon;

        if (! $salon || ! filled($salon->notification_email)) {
            return;
        }

        if (! ($salon->booking_status_email_notifications ?? false)) {
            return;
        }

        try {
            Mail::to($salon->notification_email)->send(
                new BookingStatusChangedMail($booking, $oldStatus, $newStatus)
            );
        } catch (Throwable $exception) {
            Log::warning('Booking status-change notification could not be sent.', [
                'booking_id' => $booking->id,
                'salon_id' => $salon->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'error' => $exception->getMessage(),
            ]);
        }
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
