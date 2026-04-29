<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BillingController extends Controller
{
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
