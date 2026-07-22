<?php

namespace App\Http\Controllers;

use App\Enums\RequestPriority;
use App\Enums\RequestStatus;
use App\Models\CustomerRequest;
use App\Models\Staff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerRequestController extends Controller
{
    public function update(Request $request, CustomerRequest $customerRequest): RedirectResponse
    {
        $this->authorizeOwner($request, $customerRequest);

        $data = $request->validate([
            'status' => ['sometimes', 'required', Rule::in(RequestStatus::values())],
            'priority' => ['sometimes', 'required', Rule::in(RequestPriority::values())],
            'assignee_staff_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        if (array_key_exists('assignee_staff_id', $data) && $data['assignee_staff_id'] !== null) {
            abort_unless(
                Staff::query()
                    ->where('salon_id', $customerRequest->salon_id)
                    ->whereKey($data['assignee_staff_id'])
                    ->exists(),
                422,
                'Staff invalid.'
            );
        }

        $customerRequest->update($data);

        return back()->with('success', 'Solicitare actualizata.');
    }

    private function authorizeOwner(Request $request, CustomerRequest $customerRequest): void
    {
        abort_unless($customerRequest->salon_id === $request->user()->salon?->id, 403);
    }
}
