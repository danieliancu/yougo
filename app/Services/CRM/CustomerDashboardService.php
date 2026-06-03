<?php

namespace App\Services\CRM;

use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Salon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class CustomerDashboardService
{
    public function __construct(private readonly CustomerIdentityService $identity) {}

    public function index(Salon $salon, ?string $search = null): array
    {
        $this->identity->syncSalonHistory($salon);

        $customers = $salon->customers()
            ->withCount([
                'bookings',
                'bookings as upcoming_bookings_count' => fn (Builder $query) => $query
                    ->whereDate('date', '>=', now()->toDateString())
                    ->whereIn('status', ['pending', 'confirmed']),
                'bookings as cancelled_bookings_count' => fn (Builder $query) => $query->where('status', 'cancelled'),
                'bookings as completed_bookings_count' => fn (Builder $query) => $query->where('status', 'completed'),
                'conversations',
            ])
            ->with([
                'bookings' => fn ($query) => $query
                    ->with(['service', 'staffMember'])
                    ->latest('date')
                    ->latest('time')
                    ->limit(1),
            ])
            ->when(filled($search), function (Builder $query) use ($search): void {
                $search = trim((string) $search);
                $query->where(function (Builder $inner) use ($search): void {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone_normalized', 'like', '%'.preg_replace('/\D+/', '', $search).'%');
                });
            })
            ->latest('last_seen_at')
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return [
            'items' => $this->mapPaginator($customers),
            'pagination' => [
                'current_page' => $customers->currentPage(),
                'last_page' => $customers->lastPage(),
                'per_page' => $customers->perPage(),
                'total' => $customers->total(),
                'next_page_url' => $customers->nextPageUrl(),
                'prev_page_url' => $customers->previousPageUrl(),
            ],
            'filters' => [
                'search' => $search ?: '',
            ],
            'summary' => [
                'total_customers' => $salon->customers()->count(),
                'with_phone' => $salon->customers()->whereNotNull('phone_normalized')->count(),
                'with_email' => $salon->customers()->whereNotNull('email_normalized')->count(),
                'new_this_month' => $salon->customers()->where('first_seen_at', '>=', now()->startOfMonth())->count(),
            ],
        ];
    }

    public function detail(Customer $customer): array
    {
        $customer->load([
            'bookings' => fn ($query) => $query
                ->with(['location', 'service', 'staffMember'])
                ->latest('date')
                ->latest('time'),
            'conversations' => fn ($query) => $query
                ->with(['booking.service'])
                ->latest('last_message_at')
                ->latest(),
        ]);

        $bookings = $customer->bookings;
        $conversations = $customer->conversations;
        $today = now()->toDateString();

        return [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'first_seen_at' => $customer->first_seen_at?->toIso8601String(),
                'last_seen_at' => $customer->last_seen_at?->toIso8601String(),
                'notes' => $customer->notes,
            ],
            'stats' => [
                'total_bookings' => $bookings->count(),
                'upcoming_bookings' => $bookings->whereIn('status', ['pending', 'confirmed'])->where('date', '>=', $today)->count(),
                'cancelled_bookings' => $bookings->where('status', 'cancelled')->count(),
                'completed_bookings' => $bookings->where('status', 'completed')->count(),
                'conversations' => $conversations->count(),
                'last_interaction' => $customer->last_seen_at?->toIso8601String(),
            ],
            'preferences' => [
                'service' => $this->clearPreference($bookings->pluck('service.name')->filter()->all()),
                'staff' => $this->clearPreference($bookings->map(fn (Booking $booking) => $booking->staffMember?->name ?: collect($booking->staff ?? [])->first())->filter()->all()),
            ],
            'bookings' => $bookings->map(fn (Booking $booking) => [
                'id' => $booking->id,
                'client_name' => $booking->client_name,
                'client_phone' => $booking->client_phone,
                'date' => $booking->date?->format('Y-m-d'),
                'time' => $booking->time,
                'status' => $booking->status,
                'source' => $booking->source,
                'service' => $booking->service?->only(['id', 'name', 'type']),
                'location' => $booking->location?->only(['id', 'name']),
                'staff_member' => $booking->staffMember?->only(['id', 'name']),
            ])->values()->all(),
            'conversations' => $conversations->map(fn (Conversation $conversation) => [
                'id' => $conversation->id,
                'booking_id' => $conversation->booking_id,
                'channel' => $conversation->channel,
                'contact_name' => $conversation->contact_name,
                'contact_phone' => $conversation->contact_phone,
                'contact_email' => $conversation->contact_email,
                'status' => $conversation->status,
                'intent' => $conversation->intent,
                'summary' => $conversation->summary,
                'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    private function mapPaginator(LengthAwarePaginator $customers): array
    {
        return collect($customers->items())->map(function (Customer $customer): array {
            $lastBooking = $customer->bookings->first();

            return [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'first_seen_at' => $customer->first_seen_at?->toIso8601String(),
                'last_seen_at' => $customer->last_seen_at?->toIso8601String(),
                'bookings_count' => $customer->bookings_count,
                'upcoming_bookings_count' => $customer->upcoming_bookings_count,
                'cancelled_bookings_count' => $customer->cancelled_bookings_count,
                'completed_bookings_count' => $customer->completed_bookings_count,
                'conversations_count' => $customer->conversations_count,
                'last_booking' => $lastBooking ? [
                    'id' => $lastBooking->id,
                    'date' => $lastBooking->date?->format('Y-m-d'),
                    'time' => $lastBooking->time,
                    'status' => $lastBooking->status,
                    'service_name' => $lastBooking->service?->name,
                ] : null,
            ];
        })->values()->all();
    }

    private function clearPreference(array $values): ?string
    {
        $counts = collect($values)->countBy()->sortDesc();
        if ($counts->isEmpty()) {
            return null;
        }

        $topValue = (string) $counts->keys()->first();
        $topCount = (int) $counts->first();
        $secondCount = (int) ($counts->values()->get(1) ?? 0);

        return $topCount >= 2 && $topCount > $secondCount ? $topValue : null;
    }
}
