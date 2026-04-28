<?php

namespace App\Http\Controllers;

use App\Models\Salon;
use App\Services\Dashboard\DashboardDataService;
use App\Services\Onboarding\OnboardingChecklistService;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardDataService $dashboardData, OnboardingChecklistService $onboardingChecklist, string $section = 'overview'): Response
    {
        $allowed = ['overview', 'onboarding', 'ai-settings', 'conversations', 'chat-audio', 'voice-calls', 'whatsapp', 'locations', 'staff', 'services', 'bookings', 'settings'];
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
            'staff' => fn ($query) => $query->with(['location', 'locations', 'services'])->latest(),
            'services' => fn ($query) => $query->with('staffMembers')->latest(),
            'bookings' => fn ($query) => $query->with(['location', 'service', 'staffMember'])->latest(),
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
            'onboarding' => $onboardingChecklist->forSalon($salon),
        ]);
    }
}
