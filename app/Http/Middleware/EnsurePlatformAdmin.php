<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('platform_admin')->check()) {
            return redirect()->route('platform-admin.login');
        }

        return $next($request);
    }
}
