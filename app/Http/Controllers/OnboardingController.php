<?php

namespace App\Http\Controllers;

use App\Services\Onboarding\OnboardingChecklistService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OnboardingController extends Controller
{
    public function skip(Request $request): RedirectResponse
    {
        $salon = $request->user()->salon;

        $salon->update([
            'onboarding_skipped' => true,
            'onboarding_skipped_at' => now(),
        ]);

        return back()->with('success', 'Configurarea a fost sarita. O poti finaliza oricand.');
    }

    public function complete(Request $request, OnboardingChecklistService $checklist): RedirectResponse
    {
        $salon = $request->user()->salon;
        $state = $checklist->forSalon($salon);

        if (! $state['can_complete']) {
            throw ValidationException::withMessages([
                'onboarding' => 'Completeaza pasii obligatorii inainte sa marchezi configurarea ca finalizata.',
            ]);
        }

        $salon->update([
            'onboarding_completed' => true,
            'onboarding_completed_at' => now(),
        ]);

        return back()->with('success', 'Configurarea a fost finalizata.');
    }
}
