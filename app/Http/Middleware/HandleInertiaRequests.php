<?php

namespace App\Http\Middleware;

use App\Support\BusinessTaxonomy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $cookieLocale = $request->cookie('yougo-lang');
        $locale = in_array($cookieLocale, ['ro', 'en'], true)
            ? $cookieLocale
            : ($request->user()?->salon?->display_language ?? 'ro');

        return [
            ...parent::share($request),
            'locale' => $locale,
            'auth' => [
                'user' => $request->user()?->only('id', 'name', 'email'),
                'platform_admin' => Auth::guard('platform_admin')->user()?->only('id', 'name', 'username'),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'businessTaxonomy' => fn () => BusinessTaxonomy::all(),
        ];
    }
}
