<?php

namespace App\Http\Controllers;

use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ServiceController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $salon = $request->user()->salon;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:255'],
            'staff' => ['nullable', 'array'],
            'staff.*' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'string', 'max:255'],
            'duration' => ['required', 'integer', 'min:5', 'max:1440'],
            'max_concurrent_bookings' => ['nullable', 'integer', 'min:1', 'max:100'],
            'location_ids' => ['nullable', 'array'],
            'location_ids.*' => ['integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $data['location_ids'] = $this->normalizeLocations($request, $data['location_ids'] ?? []);
        $this->validateLocations($request, $data['location_ids']);
        if ($request->has('staff')) {
            $data['staff'] = $this->normalizeStaff($data['staff'] ?? []);
        }

        $salon->services()->create($data);

        return back()->with('success', 'Serviciu adaugat.');
    }

    public function update(Request $request, Service $service): RedirectResponse
    {
        $this->authorizeOwner($request, $service);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:255'],
            'staff' => ['nullable', 'array'],
            'staff.*' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'string', 'max:255'],
            'duration' => ['required', 'integer', 'min:5', 'max:1440'],
            'max_concurrent_bookings' => ['nullable', 'integer', 'min:1', 'max:100'],
            'location_ids' => ['nullable', 'array'],
            'location_ids.*' => ['integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $data['location_ids'] = $this->normalizeLocations($request, $data['location_ids'] ?? []);
        $this->validateLocations($request, $data['location_ids']);
        if ($request->has('staff')) {
            $data['staff'] = $this->normalizeStaff($data['staff'] ?? []);
        }

        $service->update($data);

        return back()->with('success', 'Serviciu actualizat.');
    }

    public function destroy(Request $request, Service $service): RedirectResponse
    {
        $this->authorizeOwner($request, $service);
        $service->delete();

        return back()->with('success', 'Serviciu sters.');
    }

    public function updateCategories(Request $request): RedirectResponse
    {
        $salon = $request->user()->salon;

        $data = $request->validate([
            'categories' => ['required', 'array'],
            'categories.*' => ['nullable', 'string', 'max:255'],
        ]);

        $categories = collect($data['categories'])
            ->map(fn ($category) => trim((string) $category))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $salon->update([
            'service_categories' => $categories,
        ]);

        return back()->with('success', 'Categorii actualizate.');
    }

    public function updateStaff(Request $request): RedirectResponse
    {
        $salon = $request->user()->salon;

        $data = $request->validate([
            'staff' => ['required', 'array'],
            'staff.*' => ['nullable', 'string', 'max:255'],
        ]);

        $staff = collect($data['staff'])
            ->map(fn ($member) => trim((string) $member))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $salon->update([
            'service_staff' => $staff,
        ]);

        return back()->with('success', 'Staff actualizat.');
    }

    private function authorizeOwner(Request $request, Service $service): void
    {
        abort_unless($service->salon_id === $request->user()->salon?->id, 403);
    }

    private function validateLocations(Request $request, array $locationIds): void
    {
        $salon = $request->user()->salon;
        $uniqueLocationIds = array_values(array_unique($locationIds));

        if ($salon->locations()->exists() && count($uniqueLocationIds) === 0) {
            throw ValidationException::withMessages([
                'location_ids' => 'Selecteaza cel putin un branch.',
            ]);
        }

        if (count($uniqueLocationIds) === 0) {
            return;
        }

        $validLocationCount = $salon->locations()
            ->whereIn('id', $uniqueLocationIds)
            ->count();

        if ($validLocationCount !== count($uniqueLocationIds)) {
            throw ValidationException::withMessages([
                'location_ids' => 'Branch invalid.',
            ]);
        }
    }

    private function normalizeLocations(Request $request, array $locationIds): array
    {
        $salon = $request->user()->salon;
        $uniqueLocationIds = array_values(array_unique($locationIds));

        if (count($uniqueLocationIds) === 0 && $salon->locations()->count() === 1) {
            return [$salon->locations()->value('id')];
        }

        return $uniqueLocationIds;
    }

    private function normalizeStaff(array $staff): array
    {
        return collect($staff)
            ->map(fn ($member) => trim((string) $member))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
