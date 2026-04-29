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

    public function test_prompt_instructs_date_clarification_after_availability_slots(): void
    {
        $salon = $this->createSalon();

        $payload = $this->buildPayload($salon);
        $instruction = $payload['systemInstruction']['parts'][0]['text'];

        $this->assertStringContainsString('Cand comunici sloturi libere, mentioneaza intotdeauna si ziua/data', $instruction);
        $this->assertStringContainsString('nu reapela checkAvailability doar pentru aceasta clarificare', $instruction);
    }

    public function test_payload_includes_current_booking_status_from_database(): void
    {
        $salon = $this->createSalon();
        $location = $salon->locations()->create([
            'name' => 'Nordului',
            'address' => 'Sos. Nordului',
        ]);
        $service = $salon->services()->create([
            'name' => 'Extensii Tape-On',
            'price' => '125',
            'duration' => 60,
            'location_ids' => [$location->id],
        ]);
        $booking = $salon->bookings()->create([
            'location_id' => $location->id,
            'service_id' => $service->id,
            'client_name' => 'Ionici',
            'client_phone' => '85766634',
            'date' => '2026-05-30',
            'time' => '10:00',
            'status' => 'confirmed',
            'source' => 'ai_assistant',
        ]);
        $conversation = $salon->conversations()->create([
            'booking_id' => $booking->id,
            'channel' => 'chat',
            'status' => 'completed',
            'intent' => 'booking',
            'summary' => 'Booking created.',
            'last_message_at' => now(),
        ]);

        $payload = app(GeminiPayloadBuilder::class)->build($salon, [
            ['role' => 'user', 'content' => 'Sigur sunt programat?'],
        ], $conversation);
        $instruction = $payload['systemInstruction']['parts'][0]['text'];

        $this->assertStringContainsString('Statusul curent din baza de date este sursa de adevar', $instruction);
        $this->assertStringContainsString('status curent: confirmed.', $instruction);
        $this->assertStringContainsString('locatie: Nordului.', $instruction);
        $this->assertStringContainsString('serviciu: Extensii Tape-On.', $instruction);
        $this->assertStringContainsString('Aceasta conversatie este dedicata acestei programari existente.', $instruction);
        $this->assertStringContainsString('Nu apela checkAvailability sau bookBooking in aceasta conversatie pentru o programare noua.', $instruction);
        $this->assertStringContainsString('sa apese pe + si sa inceapa o conversatie noua', $instruction);
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
