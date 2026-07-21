<?php

namespace App\Http\Controllers;

use App\Services\Onboarding\OnboardingDraftPresenter;
use App\Services\Onboarding\OnboardingFieldSchema;
use App\Services\Onboarding\OnboardingImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingImportPageController extends Controller
{
    public function __invoke(Request $request, OnboardingImportService $importService): Response|RedirectResponse
    {
        $salon = $request->user()->salon()->firstOrCreate([], [
            'name' => "{$request->user()->name}'s Salon",
        ]);

        if ($salon->onboarding_state->locksNewImport()) {
            return redirect('/dashboard/overview');
        }

        $activeDraft = $importService->active($salon);

        return Inertia::render('Onboarding/Import', [
            'salon' => [
                'id' => $salon->id,
                'name' => $salon->name,
            ],
            'active_draft' => $activeDraft ? OnboardingDraftPresenter::present($activeDraft) : null,
            'field_schema' => OnboardingFieldSchema::forReview(),
        ]);
    }
}
