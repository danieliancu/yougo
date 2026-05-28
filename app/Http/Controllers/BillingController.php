<?php

namespace App\Http\Controllers;

use App\Services\Billing\StripeBillingGateway;
use App\Support\StripePlans;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class BillingController extends Controller
{
    public function checkout(Request $request, StripeBillingGateway $stripe)
    {
        $salon = $request->user()->salon;
        abort_unless($salon, 404);

        $planKeys = array_keys(config('yougo_plans', []));
        $data = $request->validate([
            'plan_key' => ['required', 'string', Rule::in($planKeys)],
        ]);

        $planKey = $data['plan_key'];
        if (! StripePlans::isPaidPlan($planKey)) {
            throw ValidationException::withMessages([
                'plan_key' => __('This plan cannot be purchased through Stripe.'),
            ]);
        }

        $priceId = StripePlans::priceIdForPlan($planKey);
        if (! $priceId) {
            throw ValidationException::withMessages([
                'plan_key' => __('Stripe is not configured for this plan yet.'),
            ]);
        }

        if (! filled(config('stripe.secret'))) {
            throw ValidationException::withMessages([
                'plan_key' => __('Stripe checkout is not configured yet.'),
            ]);
        }

        try {
            if (! $salon->stripe_customer_id) {
                $customer = $stripe->createCustomer($salon, $request->user());
                $salon->forceFill(['stripe_customer_id' => $customer['id']])->save();
            }

            $metadata = [
                'salon_id' => (string) $salon->id,
                'user_id' => (string) $request->user()->id,
                'plan_key' => $planKey,
            ];

            $session = $stripe->createCheckoutSession(
                $salon->stripe_customer_id,
                $priceId,
                url('/dashboard/billing?checkout=success'),
                url('/dashboard/billing?checkout=cancelled'),
                $metadata,
            );
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'plan_key' => __('Stripe checkout could not be created. Please try again.'),
            ]);
        }

        return response()->json(['url' => $session['url']]);
    }

    public function portal(Request $request, StripeBillingGateway $stripe)
    {
        $salon = $request->user()->salon;
        abort_unless($salon, 404);

        if (! $salon->stripe_customer_id) {
            throw ValidationException::withMessages([
                'subscription' => __('No Stripe customer exists for this business yet.'),
            ]);
        }

        if (! filled(config('stripe.secret'))) {
            throw ValidationException::withMessages([
                'subscription' => __('Stripe portal is not configured yet.'),
            ]);
        }

        try {
            $session = $stripe->createPortalSession($salon->stripe_customer_id, url('/dashboard/billing'));
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'subscription' => __('Stripe portal could not be opened. Please try again.'),
            ]);
        }

        return response()->json(['url' => $session['url']]);
    }

    public function updatePlan(Request $request)
    {
        $salon = $request->user()->salon;
        abort_unless($salon, 404);

        $plans = array_keys(config('yougo_plans', []));
        $data = $request->validate([
            'plan' => ['required', 'string', Rule::in($plans)],
        ]);

        $salon->update([
            'plan' => $data['plan'],
            'plan_started_at' => now(),
        ]);

        return back()->with('success', __('Plan updated for local testing.'));
    }
}
