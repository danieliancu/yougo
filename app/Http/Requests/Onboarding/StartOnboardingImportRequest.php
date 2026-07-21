<?php

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;

class StartOnboardingImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->salon !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'source_type' => ['required', 'string', 'in:url,manual'],
            'source_url' => ['required_if:source_type,url', 'nullable', 'string', 'max:2048'],
        ];
    }
}
