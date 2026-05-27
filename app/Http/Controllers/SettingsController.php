<?php

namespace App\Http\Controllers;

use App\Models\Salon;
use App\Support\BusinessTaxonomy;
use App\Support\YouGoServices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $salon = $user->salon;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'business_name' => ['required', 'string', 'max:255'],
            'timezone' => ['required', 'string', 'max:80'],
            'business_type' => ['required', 'string', 'max:100', Rule::in(BusinessTaxonomy::businessTypeSlugs())],
            'country' => ['nullable', 'string', 'size:2'],
            'website' => ['nullable', 'url', 'max:255'],
            'business_phone' => ['nullable', 'string', 'max:60'],
            'notification_email' => ['nullable', 'email', 'max:255'],
            'email_notifications' => ['boolean'],
            'missed_call_alerts' => ['boolean'],
            'booking_confirmations' => ['boolean'],
            'display_language' => ['required', 'string', 'max:10'],
            'date_format' => ['required', 'string', 'max:30'],
            'logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,svg', 'max:2048'],
        ]);

        $user->update(['name' => $data['name']]);

        if ($request->hasFile('logo')) {
            if ($salon->logo_path) {
                Storage::disk('public')->delete($salon->logo_path);
            }

            $data['logo_path'] = $request->file('logo')->store('logos', 'public');
        }

        $currentPlan = YouGoServices::planKey($salon->plan);
        $emailNotificationsAvailable = (bool) config("yougo_plans.{$currentPlan}.email_notifications_enabled", false);
        $phoneAlertsAvailable = YouGoServices::planHasPhoneAi($currentPlan) && YouGoServices::isServiceLive(YouGoServices::PHONE_AI);

        $salon->update([
            'name' => $data['business_name'],
            'logo_path' => $data['logo_path'] ?? $salon->logo_path,
            'timezone' => $data['timezone'],
            'mode' => $salon->mode ?: Salon::MODE_APPOINTMENT,
            'business_type' => $data['business_type'],
            'country' => strtoupper($data['country'] ?? ''),
            'website' => $data['website'] ?? null,
            'business_phone' => $data['business_phone'] ?? null,
            'notification_email' => $data['notification_email'] ?? null,
            'email_notifications' => $emailNotificationsAvailable ? $request->boolean('email_notifications') : false,
            'missed_call_alerts' => $phoneAlertsAvailable ? $request->boolean('missed_call_alerts') : false,
            'booking_confirmations' => $emailNotificationsAvailable ? $request->boolean('booking_confirmations') : false,
            'display_language' => $data['display_language'],
            'date_format' => $data['date_format'],
        ]);

        return back()->with('success', 'Setarile au fost salvate.');
    }

    public function updateLanguage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'display_language' => ['required', 'string', Rule::in(['ro', 'en'])],
        ]);

        $request->user()->salon()->firstOrCreate([], [
            'name' => "{$request->user()->name}'s Salon",
        ])->update([
            'display_language' => $data['display_language'],
        ]);

        return response()->json(['locale' => $data['display_language']]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        $salon = $user->salon;

        if ($salon?->logo_path) {
            Storage::disk('public')->delete($salon->logo_path);
        }

        Auth::guard('web')->logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('success', 'Contul a fost sters.');
    }
}
