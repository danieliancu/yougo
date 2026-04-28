<?php

namespace App\Services\Dashboard;

use App\Models\Salon;
use Illuminate\Support\Carbon;

class DashboardDataService
{
    public function overview(Salon $salon): array
    {
        $timezone = $salon->timezone ?: config('app.timezone');
        $today = Carbon::now($timezone)->toDateString();
        $weekStart = Carbon::now($timezone)->startOfWeek()->toDateString();
        $weekEnd = Carbon::now($timezone)->endOfWeek()->toDateString();

        $totalConversations = $salon->conversations()->count();
        $totalBookings = $salon->bookings()->count();

        return [
            'metrics' => [
                'total_conversations' => $totalConversations,
                'conversations_today' => $salon->conversations()->whereDate('created_at', $today)->count(),
                'open_conversations' => $salon->conversations()->where('status', 'open')->count(),
                'abandoned_conversations' => $salon->conversations()->where('intent', 'abandoned')->count(),
                'total_bookings' => $totalBookings,
                'pending_bookings' => $salon->bookings()->where('status', 'pending')->count(),
                'confirmed_bookings' => $salon->bookings()->where('status', 'confirmed')->count(),
                'completed_bookings' => $salon->bookings()->where('status', 'completed')->count(),
                'bookings_today' => $salon->bookings()->whereDate('date', $today)->count(),
                'bookings_this_week' => $salon->bookings()->whereBetween('date', [$weekStart, $weekEnd])->count(),
                'conversion_rate' => $totalConversations > 0 ? round(($totalBookings / $totalConversations) * 100, 1) : 0.0,
            ],
            'latest_conversations' => $salon->conversations()
                ->with('booking.staffMember')
                ->latest('last_message_at')
                ->latest()
                ->limit(5)
                ->get(),
            'latest_bookings' => $salon->bookings()
                ->with(['location', 'service', 'staffMember'])
                ->latest('date')
                ->latest('time')
                ->limit(5)
                ->get(),
        ];
    }
}
