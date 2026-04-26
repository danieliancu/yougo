<?php

namespace App\Http\Controllers;

use App\Models\Location;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $salon = $request->user()->salon;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'hours' => ['nullable', 'array'],
        ]);

        $salon->locations()->create($data);

        return back()->with('success', 'Locatie adaugata.');
    }

    public function update(Request $request, Location $location): RedirectResponse
    {
        $this->authorizeOwner($request, $location);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'hours' => ['nullable', 'array'],
        ]);

        $location->update($data);

        return back()->with('success', 'Locatie actualizata.');
    }

    public function destroy(Request $request, Location $location): RedirectResponse
    {
        $this->authorizeOwner($request, $location);
        $location->delete();

        return back()->with('success', 'Locatie stearsa.');
    }

    private function authorizeOwner(Request $request, Location $location): void
    {
        abort_unless($location->salon_id === $request->user()->salon?->id, 403);
    }
}
