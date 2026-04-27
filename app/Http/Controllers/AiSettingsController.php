<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AiSettingsController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $salon = $request->user()->salon()->firstOrCreate([], [
            'name' => "{$request->user()->name}'s Salon",
        ]);

        $data = $request->validate([
            'ai_assistant_name' => ['required', 'string', 'max:80'],
            'ai_tone' => ['required', 'string', Rule::in(['polite', 'friendly', 'professional', 'warm'])],
            'ai_response_style' => ['required', 'string', Rule::in(['short', 'balanced', 'detailed'])],
            'ai_language_mode' => ['required', 'string', Rule::in(['auto', 'ro', 'en'])],
            'ai_custom_instructions' => ['nullable', 'string', 'max:3000'],
            'ai_business_summary' => ['nullable', 'string', 'max:3000'],
            'ai_booking_enabled' => ['boolean'],
            'ai_collect_phone' => ['boolean'],
            'ai_handoff_message' => ['nullable', 'string', 'max:1000'],
            'ai_unknown_answer_policy' => ['required', 'string', Rule::in(['say_unknown', 'handoff'])],
        ]);

        $salon->update([
            'ai_assistant_name' => trim($data['ai_assistant_name']),
            'ai_tone' => $data['ai_tone'],
            'ai_response_style' => $data['ai_response_style'],
            'ai_language_mode' => $data['ai_language_mode'],
            'ai_custom_instructions' => $data['ai_custom_instructions'] ?? null,
            'ai_business_summary' => $data['ai_business_summary'] ?? null,
            'ai_booking_enabled' => $request->boolean('ai_booking_enabled'),
            'ai_collect_phone' => $request->boolean('ai_collect_phone'),
            'ai_handoff_message' => $data['ai_handoff_message'] ?? null,
            'ai_unknown_answer_policy' => $data['ai_unknown_answer_policy'],
        ]);

        return back()->with('success', 'Setarile AI au fost salvate.');
    }
}
