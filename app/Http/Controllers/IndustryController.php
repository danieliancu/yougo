<?php

namespace App\Http\Controllers;

use App\Support\BusinessTaxonomy;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class IndustryController extends Controller
{
    public function show(string $businessTypeSlug): Response
    {
        $businessType = BusinessTaxonomy::findBusinessType($businessTypeSlug);

        abort_unless($businessType, 404);

        return Inertia::render('Industries/Show', [
            'businessType' => $businessType,
            'seo' => [
                'title' => "YouGo AI Receptionist for {$businessType['label']}",
                'description' => $businessType['page_focus'],
            ],
        ]);
    }

    public function redirectLegacy(string $businessTypeSlug, string $industrySlug): RedirectResponse
    {
        abort_unless(BusinessTaxonomy::findBusinessType($businessTypeSlug), 404);

        return redirect()->route('industries.show', ['businessTypeSlug' => $businessTypeSlug], 301);
    }
}
