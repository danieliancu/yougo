<?php

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmOnboardingDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ownership is checked in the controller against the route-bound draft.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'expected_revision' => ['required', 'integer', 'min:0'],
            'facts' => ['sometimes', 'array'],
            'facts.*.decision' => ['required_with:facts', 'string', 'in:confirmed,corrected,excluded'],
            'excluded_locations' => ['sometimes', 'array'],
            'excluded_services' => ['sometimes', 'array'],
            'overwrite' => ['sometimes', 'array'],
        ];
    }
}
