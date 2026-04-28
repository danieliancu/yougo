<?php

namespace App\Http\Controllers;

use App\Models\Staff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StaffController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $salon = $request->user()->salon;
        $data = $this->validatedData($request);

        $locationIds = $this->validatedLocationIds($request, $data);
        $serviceIds = $this->validatedServiceIds($request, $data['service_ids'] ?? []);

        $staff = $salon->staff()->create([
            'name' => $data['name'],
            'role' => $data['role'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'location_id' => $locationIds[0] ?? null,
            'active' => $request->boolean('active', true),
            'working_hours' => $data['working_hours'] ?? null,
        ]);

        $staff->locations()->sync($locationIds);
        $staff->services()->sync($serviceIds);

        return back()->with('success', 'Membrul echipei a fost salvat cu succes.');
    }

    public function update(Request $request, Staff $staff): RedirectResponse
    {
        $this->authorizeOwner($request, $staff);

        $data = $this->validatedData($request);
        $locationIds = $this->validatedLocationIds($request, $data);
        $serviceIds = $this->validatedServiceIds($request, $data['service_ids'] ?? []);

        $staff->update([
            'name' => $data['name'],
            'role' => $data['role'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'location_id' => $locationIds[0] ?? null,
            'active' => $request->boolean('active'),
            'working_hours' => $data['working_hours'] ?? null,
        ]);

        $staff->locations()->sync($locationIds);
        $staff->services()->sync($serviceIds);

        return back()->with('success', 'Membrul echipei a fost salvat cu succes.');
    }

    public function destroy(Request $request, Staff $staff): RedirectResponse
    {
        $this->authorizeOwner($request, $staff);

        $staff->delete();

        return back()->with('success', 'Membrul echipei a fost sters cu succes.');
    }

    private function validatedData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'role' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'location_id' => ['nullable', 'integer'],
            'location_ids' => ['nullable', 'array'],
            'location_ids.*' => ['integer'],
            'active' => ['boolean'],
            'working_hours' => ['nullable', 'array'],
            'service_ids' => ['nullable', 'array'],
            'service_ids.*' => ['integer'],
        ]);
    }

    private function authorizeOwner(Request $request, Staff $staff): void
    {
        abort_unless($staff->salon_id === $request->user()->salon?->id, 403);
    }

    private function validatedLocationIds(Request $request, array $data): array
    {
        $locationIds = array_key_exists('location_ids', $data)
            ? $data['location_ids']
            : (array) ($data['location_id'] ?? []);

        $locationIds = collect($locationIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (count($locationIds) === 0) {
            return [];
        }

        $validCount = $request->user()->salon
            ->locations()
            ->whereIn('id', $locationIds)
            ->count();

        if ($validCount !== count($locationIds)) {
            throw ValidationException::withMessages([
                array_key_exists('location_ids', $data) ? 'location_ids' : 'location_id' => 'Locatie invalida.',
            ]);
        }

        return $locationIds;
    }

    private function validatedServiceIds(Request $request, array $serviceIds): array
    {
        $serviceIds = collect($serviceIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (count($serviceIds) === 0) {
            return [];
        }

        $validCount = $request->user()->salon
            ->services()
            ->whereIn('id', $serviceIds)
            ->count();

        if ($validCount !== count($serviceIds)) {
            throw ValidationException::withMessages([
                'service_ids' => 'Serviciu invalid.',
            ]);
        }

        return $serviceIds;
    }
}
