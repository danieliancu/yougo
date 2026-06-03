<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\CRM\CustomerDashboardService;
use App\Services\Dashboard\DashboardDataService;
use App\Services\Onboarding\OnboardingChecklistService;
use App\Services\Usage\UsageLimitService;
use App\Support\BusinessLocalization;
use App\Support\StripePlans;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function show(Request $request, Customer $customer, DashboardDataService $dashboardData, OnboardingChecklistService $onboardingChecklist, UsageLimitService $usageLimitService, CustomerDashboardService $customers): Response
    {
        $salon = $request->user()->salon()->firstOrCreate([], [
            'name' => "{$request->user()->name}'s Salon",
        ]);

        abort_unless($customer->salon_id === $salon->id, 404);

        $salon->ensureWidgetKey();
        $this->ensureLocalizationDefaults($salon);
        $salon->load([
            'locations' => fn ($query) => $query->latest(),
            'staff' => fn ($query) => $query->with(['location', 'locations', 'services'])->latest(),
            'services' => fn ($query) => $query->with('staffMembers')->latest(),
            'bookings' => fn ($query) => $query->with(['location', 'service', 'staffMember'])->latest(),
            'whatsappIntegration',
            'conversations' => fn ($query) => $query->with(['messages' => fn ($messageQuery) => $messageQuery->oldest(), 'booking.location', 'booking.service'])->latest('last_message_at')->oldest('id'),
        ]);

        return Inertia::render('Dashboard/Index', [
            'section' => 'customer-detail',
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
            'crm' => $customers->detail($customer),
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

    private function ensureLocalizationDefaults($salon): void
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
