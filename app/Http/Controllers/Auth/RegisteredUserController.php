<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Salon;
use App\Models\User;
use App\Support\BusinessTaxonomy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'business_name' => ['required', 'string', 'max:255'],
            'business_type' => ['required', 'string', 'max:100', Rule::in(BusinessTaxonomy::businessTypeSlugs())],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        Salon::create([
            'user_id' => $user->id,
            'name' => $data['business_name'],
            'business_type' => $data['business_type'],
            'mode' => Salon::MODE_APPOINTMENT,
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard.section', ['section' => 'onboarding'])->with('success', 'Cont creat cu succes.');
    }
}
