<?php

namespace App\Http\Controllers;

use App\Models\Salon;
use App\Services\Business\RequestDashboardService;
use App\Services\CRM\CustomerDashboardService;
use App\Services\Dashboard\DashboardDataService;
use App\Services\Onboarding\OnboardingChecklistService;
use App\Services\Usage\UsageLimitService;
use App\Support\BusinessLocalization;
use App\Support\DashboardModuleRegistry;
use App\Support\StripePlans;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardDataService $dashboardData, OnboardingChecklistService $onboardingChecklist, UsageLimitService $usageLimitService, CustomerDashboardService $customers, RequestDashboardService $requestsDashboard, string $section = 'overview'): Response
    {
        $allowed = ['overview', 'onboarding', 'ai-settings', 'conversations', 'voice-calls', 'whatsapp', 'locations', 'staff', 'services', 'bookings', 'customers', 'requests', 'widget', 'billing', 'settings'];
        abort_unless(in_array($section, $allowed, true), 404);

        $salon = $request->user()->salon()->firstOrCreate([], [
            'name' => "{$request->user()->name}'s Salon",
        ]);
        $salon->ensureWidgetKey();
        $this->ensureLocalizationDefaults($salon);
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
            'bookings' => fn ($query) => $query
                ->with([
                    'location',
                    'service',
                    'staffMember',
                    'conversations' => fn ($conversationQuery) => $conversationQuery
                        ->select(['id', 'booking_id', 'metadata', 'last_message_at'])
                        ->latest('last_message_at')
                        ->latest(),
                ])
                ->latest(),
            'whatsappIntegration',
            'conversations' => fn ($query) => $query
                ->with([
                    'messages' => fn ($messageQuery) => $messageQuery->oldest(),
                    'booking.location',
                    'booking.service',
                ])
                ->latest('last_message_at')
                ->oldest('id'),
        ]);

        return Inertia::render('Dashboard/Index', [
            'section' => $section,
            'salon' => $salon,
            'overview' => $dashboardData->overview($salon),
            'onboarding' => $onboardingChecklist->forSalon($salon),
            'billing' => [
                'summary' => $usageLimitService->usageSummary($salon),
                'plans' => $usageLimitService->plans(),
                'services' => $usageLimitService->services(),
                'whatsapp_integration' => $salon->whatsappIntegration,
                'stripe' => [
                    'subscription_status' => $salon->subscription_status,
                    'stripe_customer_exists' => filled($salon->stripe_customer_id),
                    'stripe_subscription_exists' => filled($salon->stripe_subscription_id),
                    'subscription_current_period_end' => $salon->subscription_current_period_end?->toIso8601String(),
                    'paid_plan_keys' => StripePlans::paidPlanKeys(),
                    'configured_prices' => collect(StripePlans::paidPlanKeys())
                        ->mapWithKeys(fn (string $planKey) => [$planKey => filled(StripePlans::priceIdForPlan($planKey))])
                        ->all(),
                    'payment_warning' => in_array($salon->subscription_status, ['past_due', 'unpaid', 'payment_failed'], true),
                ],
            ],
            'crm' => $section === 'customers'
                ? $customers->index($salon, $request->string('search')->toString())
                : null,
            'modules' => DashboardModuleRegistry::forSalon($salon),
            'requests' => $section === 'requests'
                ? $requestsDashboard->index($salon, [
                    'status' => $request->string('status')->toString(),
                    'priority' => $request->string('priority')->toString(),
                    'type' => $request->string('type')->toString(),
                    'search' => $request->string('search')->toString(),
                ])
                : null,
            'localization' => [
                'countries' => BusinessLocalization::countryOptions($request->user()?->salon?->display_language ?? config('app.locale', 'ro')),
                'timezones' => BusinessLocalization::timezoneOptions(),
                'date_formats' => BusinessLocalization::allDateFormats(),
                'service_currencies' => BusinessLocalization::serviceCurrencyOptions($salon->country),
                'defaults' => [
                    'country' => BusinessLocalization::normalizeCountry($salon->country),
                    'currency' => BusinessLocalization::currencyFor($salon->country),
                    'phone_prefix' => BusinessLocalization::phonePrefixFor($salon->country),
                    'timezone' => BusinessLocalization::timezoneFor($salon->country),
                    'date_format' => BusinessLocalization::dateFormatFor($salon->country),
                    'default_language' => BusinessLocalization::defaultLanguageFor($salon->country),
                ],
            ],
            'appUrl' => $request->getSchemeAndHttpHost(),
        ]);
    }

    private function ensureLocalizationDefaults(Salon $salon): void
    {
        $country = BusinessLocalization::normalizeCountry($salon->country);
        $updates = [];

        if (! filled($salon->country)) {
            $updates['country'] = $country;
        }

        if (! filled($salon->timezone)) {
            $updates['timezone'] = BusinessLocalization::timezoneFor($country);
        }

        if (! filled($salon->currency)) {
            $updates['currency'] = BusinessLocalization::currencyFor($country);
        }

        if (! filled($salon->phone_prefix)) {
            $updates['phone_prefix'] = BusinessLocalization::phonePrefixFor($country);
        }

        if (! filled($salon->date_format)) {
            $updates['date_format'] = BusinessLocalization::dateFormatFor($country);
        }

        if ($updates !== []) {
            $salon->forceFill($updates)->save();
            $salon->refresh();
        }
    }
}
