<?php

namespace App\Services\Assistant;

use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Salon;
use App\Services\CRM\CustomerIdentityService;
use Illuminate\Support\Carbon;

class CustomerBookingContextService
{
    public function __construct(private readonly CustomerIdentityService $identity) {}

    public function findRecentForCustomer(Salon $salon, ?Conversation $conversation = null, ?array $knownContact = null): ?array
    {
        $phones = $this->candidatePhones($conversation, $knownContact);
        if ($phones === []) {
            return null;
        }

        $bookings = $salon->bookings()
            ->with(['location', 'service', 'staffMember'])
            ->whereNotNull('client_phone')
            ->latest('updated_at')
            ->latest('created_at')
            ->limit(250)
            ->get()
            ->filter(fn (Booking $booking) => in_array($this->identity->normalizePhone($booking->client_phone), $phones, true));

        if ($bookings->isEmpty()) {
            return null;
        }

        $now = Carbon::now();
        $booking = $bookings
            ->sortBy(function (Booking $booking) use ($now): array {
                $startsAt = $this->bookingStartsAt($booking);
                $futureRank = $startsAt && $startsAt->greaterThanOrEqualTo($now) ? 0 : 1;
                $timeRank = $futureRank === 0
                    ? $startsAt?->timestamp ?? PHP_INT_MAX
                    : -1 * ($booking->updated_at?->timestamp ?? $booking->created_at?->timestamp ?? 0);

                return [$futureRank, $timeRank];
            })
            ->first();

        return $booking ? $this->toContext($booking) : null;
    }

    private function candidatePhones(?Conversation $conversation, ?array $knownContact): array
    {
        return collect([
            $conversation?->external_contact_id,
            $conversation?->contact_phone,
            $knownContact['phone'] ?? null,
        ])
            ->map(fn ($phone) => $this->identity->normalizePhone($phone))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function bookingStartsAt(Booking $booking): ?Carbon
    {
        $date = $booking->date?->format('Y-m-d');
        $time = trim((string) $booking->time);

        if (! $date || $time === '') {
            return null;
        }

        try {
            return Carbon::parse("{$date} {$time}");
        } catch (\Throwable) {
            return null;
        }
    }

    private function toContext(Booking $booking): array
    {
        $status = (string) $booking->status;

        return [
            'id' => $booking->id,
            'status' => $status,
            'date' => $booking->date?->format('Y-m-d'),
            'time' => $booking->time,
            'service_name' => $booking->service?->name,
            'location_name' => $booking->location?->name,
            'staff_name' => $booking->staffMember?->name ?: collect($booking->staff ?? [])->filter()->implode(', '),
            'client_name' => $booking->client_name,
            'client_phone' => $booking->client_phone,
            'is_historical' => $booking->isArchivedForDashboard(),
            'is_confirmed_or_stable' => in_array($status, ['confirmed', 'cancelled', 'completed'], true),
            'is_pending' => $status === 'pending',
        ];
    }
}
