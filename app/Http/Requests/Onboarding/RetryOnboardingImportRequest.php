<?php

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;

class RetryOnboardingImportRequest extends FormRequest
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
        return [];
    }
}
