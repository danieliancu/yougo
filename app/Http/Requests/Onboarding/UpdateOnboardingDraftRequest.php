<?php

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOnboardingDraftRequest extends FormRequest
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
            'corrections' => ['sometimes', 'array'],
            'corrections.*.value' => ['required'],
        ];
    }
}
