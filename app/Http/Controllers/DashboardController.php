<?php

namespace App\Http\Controllers;

use App\Models\Salon;
use App\Services\Dashboard\DashboardDataService;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardDataService $dashboardData, string $section = 'overview'): Response
    {
        $allowed = ['overview', 'ai-settings', 'conversations', 'chat-audio', 'voice-calls', 'whatsapp', 'locations', 'services', 'bookings', 'settings'];
        abort_unless(in_array($section, $allowed, true), 404);

        $salon = $request->user()->salon()->firstOrCreate([], [
            'name' => "{$request->user()->name}'s Salon",
        ]);
        $now = Carbon::now($salon->timezone ?: config('app.timezone'));

        $salon->bookings()
            ->with('service')
            ->whereIn('status', ['pending', 'confirmed'])
            ->whereDate('date', '<=', $now->toDateString())
            ->get()
            ->each(function ($booking) use ($now) {
                [$h, $m] = array_map('intval', explode(':', $booking->time));
                $duration = $booking->service?->duration ?? 0;
                $endTime = Carbon::create(
                    $booking->date->year,
                    $booking->date->month,
                    $booking->date->day,
                    $h, $m, 0,
                    $now->timezone
                )->addMinutes($duration);
                if ($endTime <= $now) {
                    $booking->update(['status' => 'completed']);
                }
            });

        $salon->conversations()
            ->where('intent', 'inquiry')
            ->where('status', 'open')
            ->where('last_message_at', '<', now()->subHour())
            ->update(['intent' => 'abandoned', 'status' => 'completed']);

        $salon->load([
            'locations' => fn ($query) => $query->latest(),
            'services' => fn ($query) => $query->latest(),
            'bookings' => fn ($query) => $query->with(['location', 'service'])->latest(),
            'conversations' => fn ($query) => $query
                ->with([
                    'messages' => fn ($messageQuery) => $messageQuery->oldest(),
                    'booking.location',
                    'booking.service',
                ])
                ->latest('last_message_at')
                ->latest(),
        ]);

        return Inertia::render('Dashboard/Index', [
            'section' => $section,
            'salon' => $salon,
            'overview' => $dashboardData->overview($salon),
        ]);
    }
}
