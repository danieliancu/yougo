<?php

namespace App\Http\Controllers;

use App\Models\Salon;
use App\Services\Business\IndustryDefaultsService;
use App\Services\Onboarding\OnboardingChecklistService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

    /**
     * The single user-facing action that activates a capability configuration (Task 4
     * §10 — the mandatory "Ce vrei sa faca YouGo pentru clientii tai?" screen). Whether
     * the user accepted the recommendation as-is or changed it is inferred by comparing
     * against `recommended_*` — IndustryDefaultsService::confirm() uses that to mark the
     * result `confirmed` vs `custom`.
     */
    public function confirmCapabilities(Request $request, IndustryDefaultsService $industryDefaults): RedirectResponse
    {
        $salon = $request->user()->salon;
        abort_unless($salon, 403);

        $allowed = [Salon::CAPABILITY_APPOINTMENT, Salon::CAPABILITY_REQUEST];

        $data = $request->validate([
            'primary_capability' => ['required', 'string', Rule::in($allowed)],
            'secondary_capabilities' => ['array'],
            'secondary_capabilities.*' => ['string', Rule::in($allowed)],
        ]);

        $secondary = array_values($data['secondary_capabilities'] ?? []);
        $customized = $data['primary_capability'] !== $salon->recommended_primary_capability
            || $secondary !== array_values($salon->recommended_secondary_capabilities ?? []);

        $industryDefaults->confirm($salon, $data['primary_capability'], $secondary, $customized);

        return back()->with('success', 'Configurarea a fost confirmata.');
    }
}
