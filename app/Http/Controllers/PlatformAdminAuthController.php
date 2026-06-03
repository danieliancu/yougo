<?php

namespace App\Http\Controllers;

use App\Models\PlatformAdmin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PlatformAdminAuthController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        if (Auth::guard('platform_admin')->check()) {
            return redirect()->route('platform-admin.overview');
        }

        return Inertia::render('PlatformAdmin/Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $admin = PlatformAdmin::query()
            ->where('username', $credentials['username'])
            ->first();

        if (! $admin || ! Hash::check($credentials['password'], $admin->password)) {
            throw ValidationException::withMessages([
                'username' => 'These credentials do not have platform admin access.',
            ]);
        }

        Auth::guard('platform_admin')->login($admin, $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->intended(route('platform-admin.overview'));
    }

    public function edit(): Response
    {
        return Inertia::render('PlatformAdmin/Settings');
    }

    public function update(Request $request): RedirectResponse
    {
        /** @var PlatformAdmin $admin */
        $admin = Auth::guard('platform_admin')->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', Rule::unique('platform_admins', 'username')->ignore($admin->id)],
            'current_password' => ['required', 'string'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);

        if (! Hash::check($validated['current_password'], $admin->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'The current admin password is incorrect.',
            ]);
        }

        $admin->name = $validated['name'];
        $admin->username = $validated['username'];

        if (! empty($validated['password'])) {
            $admin->password = $validated['password'];
        }

        $admin->save();

        return back()->with('success', 'Platform Admin credentials updated.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('platform_admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('platform-admin.login');
    }
}
