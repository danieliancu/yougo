<?php

namespace Tests\Feature;

use App\Models\Salon;
use App\Models\User;
use App\Services\Assistant\GeminiPayloadBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssistantPromptTest extends TestCase
{
    use RefreshDatabase;

    public function test_assistant_payload_includes_ai_settings(): void
    {
        $salon = $this->createSalon([
            'ai_assistant_name' => 'Mara',
            'ai_tone' => 'professional',
            'ai_response_style' => 'detailed',
            'ai_language_mode' => 'en',
            'ai_custom_instructions' => 'Mention the cancellation policy.',
            'ai_business_summary' => 'Premium appointment studio.',
        ]);

        $payload = $this->buildPayload($salon);
        $instruction = $payload['systemInstruction']['parts'][0]['text'];

        $this->assertStringContainsString('Esti Mara', $instruction);
        $this->assertStringContainsString('Premium appointment studio.', $instruction);
        $this->assertStringContainsString('Mention the cancellation policy.', $instruction);
        $this->assertStringContainsString('Raspunde intotdeauna in engleza.', $instruction);
    }

    public function test_booking_tool_is_omitted_when_ai_booking_is_disabled(): void
    {
        $salon = $this->createSalon([
            'ai_booking_enabled' => false,
        ]);

        $payload = $this->buildPayload($salon);
        $instruction = $payload['systemInstruction']['parts'][0]['text'];

        $this->assertArrayNotHasKey('tools', $payload);
        $this->assertStringContainsString('Nu crea programari', $instruction);
    }

    private function createSalon(array $attributes = []): Salon
    {
        $user = User::factory()->create();

        return Salon::query()->create(array_merge([
            'user_id' => $user->id,
            'name' => 'YouGo Studio',
        ], $attributes));
    }

    private function buildPayload(Salon $salon): array
    {
        $salon->load(['locations', 'services']);

        return app(GeminiPayloadBuilder::class)->build($salon, [
            ['role' => 'user', 'content' => 'Hello'],
        ]);
    }
}
