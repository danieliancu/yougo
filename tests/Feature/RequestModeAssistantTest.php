<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\CustomerRequest;
use App\Models\Salon;
use App\Models\User;
use App\Services\Assistant\GeminiPayloadBuilder;
use App\Services\Modes\Request\RequestToolHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Covers Task 4 §9/§6 AI-side requirements for the `request` capability, mirroring
 * BusinessModeTest's style. Kept as its own file (rather than editing
 * AssistantPromptTest/AssistantWidgetTest/WhatsappIntegrationTest directly) to avoid
 * touching the already-extensive, sensitive appointment-path regression coverage.
 */
class RequestModeAssistantTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_only_salon_does_not_expose_booking_tool(): void
    {
        $salon = $this->createSalon(['appointment' => false, 'request' => true]);

        $payload = app(GeminiPayloadBuilder::class)->build($salon, [
            ['role' => 'user', 'content' => 'Vreau o oferta'],
        ]);
        $names = collect($payload['tools'][0]['functionDeclarations'])->pluck('name');

        $this->assertContains('createRequest', $names);
        $this->assertNotContains('bookBooking', $names);
    }

    public function test_appointment_only_salon_does_not_expose_request_tool(): void
    {
        $salon = $this->createSalon(['appointment' => true, 'request' => false]);

        $payload = app(GeminiPayloadBuilder::class)->build($salon, [
            ['role' => 'user', 'content' => 'Vreau o programare'],
        ]);
        $names = collect($payload['tools'][0]['functionDeclarations'])->pluck('name');

        $this->assertContains('bookBooking', $names);
        $this->assertNotContains('createRequest', $names);
    }

    public function test_mixed_mode_salon_exposes_both_tools_appointment_first(): void
    {
        $salon = $this->createSalon(['appointment' => true, 'request' => true]);

        $payload = app(GeminiPayloadBuilder::class)->build($salon, [
            ['role' => 'user', 'content' => 'Buna'],
        ]);
        $names = collect($payload['tools'][0]['functionDeclarations'])->pluck('name')->values();

        $this->assertSame(['bookBooking', 'checkAvailability', 'createRequest'], $names->all());
    }

    public function test_request_prompt_context_is_injected_for_request_capability(): void
    {
        $salon = $this->createSalon(['appointment' => false, 'request' => true], businessType: 'home-services');

        $payload = app(GeminiPayloadBuilder::class)->build($salon, [
            ['role' => 'user', 'content' => 'Am o teava sparta'],
        ]);
        $instruction = $payload['systemInstruction']['parts'][0]['text'];

        $this->assertStringContainsString('Request mode este activ', $instruction);
        $this->assertStringContainsString('Reguli de siguranta', $instruction);
        $this->assertStringContainsString('createRequest', $instruction);
    }

    public function test_salon_with_no_capability_gets_information_only_fallback(): void
    {
        $salon = $this->createSalon(['appointment' => false, 'request' => false]);

        $payload = app(GeminiPayloadBuilder::class)->build($salon, [
            ['role' => 'user', 'content' => 'Buna'],
        ]);
        $instruction = $payload['systemInstruction']['parts'][0]['text'];

        $this->assertArrayNotHasKey('tools', $payload);
        $this->assertStringContainsString('Modul curent nu este appointment', $instruction);
    }

    public function test_reservation_never_gets_a_tool_or_context(): void
    {
        $salon = $this->createSalon(['appointment' => false, 'request' => true]);
        $instructionText = app(GeminiPayloadBuilder::class)->build($salon, [
            ['role' => 'user', 'content' => 'Buna'],
        ])['systemInstruction']['parts'][0]['text'];

        $this->assertStringNotContainsString('reservation', strtolower($instructionText));

        $this->expectException(\InvalidArgumentException::class);
        $salon->setCapabilities('reservation', ['reservation'], Salon::CAPABILITIES_SOURCE_CUSTOM);
    }

    public function test_website_chat_creates_a_request(): void
    {
        config(['services.gemini.key' => 'fake-key']);
        $salon = $this->createSalon(['appointment' => false, 'request' => true]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiFunctionCallResponse('createRequest', [
                'type' => 'quote',
                'priority' => 'normal',
                'description' => 'Vreau o oferta pentru un gard',
                'client_name' => 'Ana Pop',
                'client_phone' => '0722000000',
            ])),
        ]);

        $response = $this->postJson("/assistant/{$salon->id}/chat", [
            'messages' => [['role' => 'user', 'content' => 'Vreau o oferta pentru un gard']],
        ]);

        $response->assertOk();
        $this->assertSame(1, CustomerRequest::query()->count());
        $conversation = Conversation::query()->latest()->first();
        $this->assertSame('customer_request', $conversation->result_type);
    }

    public function test_urgent_condition_sets_urgent_priority(): void
    {
        $salon = $this->createSalon(['appointment' => false, 'request' => true]);
        $conversation = $salon->conversations()->create(['channel' => 'chat', 'status' => 'open']);

        $request = app(RequestToolHandler::class)->handle($salon, $conversation, [
            'name' => 'createRequest',
            'args' => [
                'type' => 'job',
                'priority' => 'urgent',
                'description' => 'Scurgere de gaz',
                'client_name' => 'Ion',
                'client_phone' => '0722000001',
            ],
        ]);

        $this->assertSame('urgent', $request->priority->value);
    }

    public function test_invalid_ai_output_is_rejected_server_side(): void
    {
        $salon = $this->createSalon(['appointment' => false, 'request' => true]);
        $conversation = $salon->conversations()->create(['channel' => 'chat', 'status' => 'open']);

        $this->expectException(ValidationException::class);

        app(RequestToolHandler::class)->handle($salon, $conversation, [
            'name' => 'createRequest',
            'args' => [
                'type' => 'not-a-real-type',
                'priority' => 'urgent',
                'description' => 'x',
                'client_name' => 'Ion',
                'client_phone' => '0722000001',
            ],
        ]);
    }

    public function test_reservation_cannot_be_created_via_ai(): void
    {
        $salon = $this->createSalon(['appointment' => false, 'request' => true]);

        $payload = app(GeminiPayloadBuilder::class)->build($salon, [
            ['role' => 'user', 'content' => 'Vreau o rezervare la restaurant'],
        ]);
        $names = collect($payload['tools'][0]['functionDeclarations'])->pluck('name');

        $this->assertNotContains('createReservation', $names);
    }

    private function createSalon(array $capabilities, string $businessType = 'salon-beauty'): Salon
    {
        $user = User::factory()->create();
        $salon = Salon::query()->create(['user_id' => $user->id, 'name' => 'YouGo Studio', 'business_type' => $businessType]);

        $enabled = array_keys(array_filter($capabilities));
        $primary = $enabled[0] ?? Salon::CAPABILITY_APPOINTMENT;

        if ($enabled === []) {
            $salon->forceFill(['primary_capability' => null, 'enabled_capabilities' => [], 'capabilities_source' => Salon::CAPABILITIES_SOURCE_CUSTOM])->save();
        } else {
            $salon->setCapabilities($primary, $enabled, Salon::CAPABILITIES_SOURCE_CUSTOM);
        }

        return $salon->fresh();
    }

    private function geminiFunctionCallResponse(string $name, array $args): array
    {
        return [
            'candidates' => [[
                'content' => [
                    'parts' => [[
                        'functionCall' => ['name' => $name, 'args' => $args],
                    ]],
                ],
                'finishReason' => 'STOP',
            ]],
        ];
    }
}
