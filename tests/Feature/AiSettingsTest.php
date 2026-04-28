<?php

namespace Tests\Feature;

use App\Models\Salon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_owner_can_update_ai_settings(): void
    {
        $user = User::factory()->create();
        $salon = Salon::query()->create([
            'user_id' => $user->id,
            'name' => 'YouGo Studio',
            'business_type' => 'auto-service',
        ]);

        $response = $this->actingAs($user)->put('/ai-settings', [
            'ai_assistant_name' => 'Mara',
            'ai_tone' => 'friendly',
            'ai_response_style' => 'balanced',
            'ai_language_mode' => 'ro',
            'ai_custom_instructions' => 'Mentioneaza politica de anulare.',
            'ai_business_summary' => 'Studio pentru servicii rapide.',
            'ai_industry_categories' => ['mot-inspection', 'tyres', 'car-diagnostics'],
            'ai_main_focus' => 'mot-inspection',
            'ai_custom_context' => ['flote comerciale', 'urgente'],
            'ai_booking_enabled' => false,
            'ai_collect_phone' => false,
            'ai_handoff_message' => 'Un coleg va reveni cu detalii.',
            'ai_unknown_answer_policy' => 'handoff',
        ]);

        $response->assertRedirect();

        $salon->refresh();
        $this->assertSame('Mara', $salon->ai_assistant_name);
        $this->assertSame('friendly', $salon->ai_tone);
        $this->assertSame('balanced', $salon->ai_response_style);
        $this->assertSame('ro', $salon->ai_language_mode);
        $this->assertSame('Mentioneaza politica de anulare.', $salon->ai_custom_instructions);
        $this->assertSame('Studio pentru servicii rapide.', $salon->ai_business_summary);
        $this->assertSame(['mot-inspection', 'tyres', 'car-diagnostics'], $salon->ai_industry_categories);
        $this->assertSame('mot-inspection', $salon->ai_main_focus);
        $this->assertSame(['flote comerciale', 'urgente'], $salon->ai_custom_context);
        $this->assertFalse($salon->ai_booking_enabled);
        $this->assertFalse($salon->ai_collect_phone);
        $this->assertSame('Un coleg va reveni cu detalii.', $salon->ai_handoff_message);
        $this->assertSame('handoff', $salon->ai_unknown_answer_policy);
    }

    public function test_ai_settings_rejects_category_outside_selected_business_type(): void
    {
        $user = User::factory()->create();
        $user->salon()->create([
            'name' => 'Garage',
            'business_type' => 'auto-service',
        ]);

        $response = $this->actingAs($user)->from('/dashboard/ai-settings')->put('/ai-settings', $this->validAiSettings([
            'ai_industry_categories' => ['medical-clinic'],
        ]));

        $response->assertRedirect('/dashboard/ai-settings');
        $response->assertSessionHasErrors('ai_industry_categories');
    }

    public function test_ai_settings_rejects_main_focus_when_it_is_not_selected(): void
    {
        $user = User::factory()->create();
        $user->salon()->create([
            'name' => 'Garage',
            'business_type' => 'auto-service',
        ]);

        $response = $this->actingAs($user)->from('/dashboard/ai-settings')->put('/ai-settings', $this->validAiSettings([
            'ai_industry_categories' => ['tyres'],
            'ai_main_focus' => 'mot-inspection',
        ]));

        $response->assertRedirect('/dashboard/ai-settings');
        $response->assertSessionHasErrors('ai_main_focus');
    }

    public function test_unauthenticated_users_cannot_update_ai_settings(): void
    {
        $response = $this->put('/ai-settings', [
            'ai_assistant_name' => 'Mara',
            'ai_tone' => 'friendly',
            'ai_response_style' => 'balanced',
            'ai_language_mode' => 'ro',
            'ai_unknown_answer_policy' => 'handoff',
        ]);

        $response->assertRedirect('/login');
    }

    private function validAiSettings(array $overrides = []): array
    {
        return array_merge([
            'ai_assistant_name' => 'Mara',
            'ai_tone' => 'friendly',
            'ai_response_style' => 'balanced',
            'ai_language_mode' => 'ro',
            'ai_custom_instructions' => '',
            'ai_business_summary' => '',
            'ai_industry_categories' => ['mot-inspection'],
            'ai_main_focus' => '',
            'ai_custom_context' => [],
            'ai_booking_enabled' => true,
            'ai_collect_phone' => true,
            'ai_handoff_message' => '',
            'ai_unknown_answer_policy' => 'handoff',
        ], $overrides);
    }
}
