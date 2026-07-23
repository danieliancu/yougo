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

    /**
     * Requirement 1: an unanswerable "status" style question (e.g. "cand vor fi analizele
     * mele gata?") must no longer be met with a flat phone/email fallback when `request` is
     * active — the system instruction must bridge into offering createRequest instead.
     */
    public function test_unknown_answer_rule_offers_request_for_status_questions_when_request_active(): void
    {
        $salon = $this->createSalon(['appointment' => true, 'request' => true], businessType: 'clinic-healthcare');

        $payload = app(GeminiPayloadBuilder::class)->build($salon, [
            ['role' => 'user', 'content' => 'Cand vor fi analizele mele gata?'],
        ]);
        $instruction = $payload['systemInstruction']['parts'][0]['text'];

        $this->assertStringContainsString('are nevoie de o verificare, o actiune sau un raspuns ulterior din partea echipei', $instruction);
        $this->assertStringContainsString('ofera explicit sa trimiti o solicitare catre echipa folosind functia createRequest', $instruction);
        $this->assertStringContainsString('Nu transforma automat orice intrebare fara raspuns intr-o solicitare', $instruction);
        $this->assertStringContainsString('Daca clientul refuza trimiterea solicitarii, ofera-i in schimb datele de contact', $instruction);
    }

    /**
     * Requirement 2: once the required fields are collected, exactly one CustomerRequest is
     * created and linked to the conversation via result_type/result_id (ConversationResultService).
     */
    public function test_clinic_status_question_creates_a_single_linked_customer_request(): void
    {
        config(['services.gemini.key' => 'fake-key']);
        $salon = $this->createSalon(['appointment' => true, 'request' => true], businessType: 'clinic-healthcare');

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiFunctionCallResponse('createRequest', [
                'type' => 'information',
                'priority' => 'normal',
                'description' => 'Verificare stadiu analize pentru pacient',
                'client_name' => 'Maria Ionescu',
                'client_phone' => '0722000111',
            ])),
        ]);

        $response = $this->postJson("/assistant/{$salon->id}/chat", [
            'messages' => [['role' => 'user', 'content' => 'Cand vor fi analizele mele gata?']],
        ]);

        $response->assertOk();
        $this->assertSame(1, CustomerRequest::query()->count());

        $customerRequest = CustomerRequest::query()->first();
        $conversation = Conversation::query()->latest()->first();
        $this->assertSame('customer_request', $conversation->result_type);
        $this->assertSame($customerRequest->id, $conversation->result_id);
        $this->assertSame('information', $customerRequest->type->value);
    }

    /**
     * Requirement 3: retrying the same tool call on the same conversation must never create
     * a duplicate CustomerRequest (ConversationResultService's row-lock/idempotency guard).
     */
    public function test_retrying_create_request_on_same_conversation_does_not_duplicate(): void
    {
        config(['services.gemini.key' => 'fake-key']);
        $salon = $this->createSalon(['appointment' => false, 'request' => true], businessType: 'clinic-healthcare');

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiFunctionCallResponse('createRequest', [
                'type' => 'information',
                'priority' => 'normal',
                'description' => 'Verificare stadiu analize pentru pacient',
                'client_name' => 'Maria Ionescu',
                'client_phone' => '0722000111',
            ])),
        ]);

        $first = $this->postJson("/assistant/{$salon->id}/chat", [
            'messages' => [['role' => 'user', 'content' => 'Cand vor fi analizele mele gata?']],
        ]);
        $first->assertOk();
        $conversationId = $first->json('conversation_id');

        $second = $this->postJson("/assistant/{$salon->id}/chat", [
            'conversation_id' => $conversationId,
            'messages' => [
                ['role' => 'user', 'content' => 'Cand vor fi analizele mele gata?'],
                ['role' => 'assistant', 'content' => 'Am inregistrat solicitarea ta.'],
                ['role' => 'user', 'content' => 'Cand vor fi analizele mele gata?'],
            ],
        ]);
        $second->assertOk();

        $this->assertSame(1, CustomerRequest::query()->count());
    }

    /**
     * Requirement 4: without the `request` capability, the classic phone/email fallback
     * wording is preserved and no createRequest bridging language or tool is present.
     */
    public function test_salon_without_request_capability_keeps_classic_fallback(): void
    {
        $salon = $this->createSalon(['appointment' => true, 'request' => false], businessType: 'clinic-healthcare');

        $payload = app(GeminiPayloadBuilder::class)->build($salon, [
            ['role' => 'user', 'content' => 'Cand vor fi analizele mele gata?'],
        ]);
        $instruction = $payload['systemInstruction']['parts'][0]['text'];
        $names = collect($payload['tools'][0]['functionDeclarations'])->pluck('name');

        $this->assertStringContainsString('Daca nu stii raspunsul din informatiile configurate, spune clar ca nu ai acea informatie.', $instruction);
        $this->assertStringNotContainsString('createRequest', $instruction);
        $this->assertNotContains('createRequest', $names);
    }

    /**
     * Requirement 5: the instruction explicitly tells the AI not to force every unanswered
     * question into a Request — plain informational questions keep the normal fallback.
     */
    public function test_unknown_answer_rule_preserves_plain_informational_fallback(): void
    {
        $salon = $this->createSalon(['appointment' => true, 'request' => true]);

        $payload = app(GeminiPayloadBuilder::class)->build($salon, [
            ['role' => 'user', 'content' => 'Ce culoare are peretele din salon?'],
        ]);
        $instruction = $payload['systemInstruction']['parts'][0]['text'];

        $this->assertStringContainsString('Pentru o intrebare pur informativa fara raspuns configurat, spune clar ca nu ai acea informatie', $instruction);
        $this->assertStringContainsString('Nu transforma automat orice intrebare fara raspuns intr-o solicitare', $instruction);
    }

    /**
     * Requirement 6: the same generic mechanism (not medical-specific) applies to a
     * non-medical industry — a callback/status request for a home-services business.
     */
    public function test_non_medical_status_question_uses_the_same_generic_request_mechanism(): void
    {
        config(['services.gemini.key' => 'fake-key']);
        $salon = $this->createSalon(['appointment' => false, 'request' => true], businessType: 'home-services');

        $payload = app(GeminiPayloadBuilder::class)->build($salon, [
            ['role' => 'user', 'content' => 'In ce stadiu este interventia la mine acasa?'],
        ]);
        $instruction = $payload['systemInstruction']['parts'][0]['text'];
        $this->assertStringContainsString('ofera explicit sa trimiti o solicitare catre echipa folosind functia createRequest', $instruction);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiFunctionCallResponse('createRequest', [
                'type' => 'information',
                'priority' => 'normal',
                'description' => 'Verificare stadiu interventie la domiciliu',
                'client_name' => 'George Marin',
                'client_phone' => '0733000222',
            ])),
        ]);

        $response = $this->postJson("/assistant/{$salon->id}/chat", [
            'messages' => [['role' => 'user', 'content' => 'In ce stadiu este interventia la mine acasa?']],
        ]);

        $response->assertOk();
        $this->assertSame(1, CustomerRequest::query()->count());
    }

    /**
     * Requirement 7: in mixed mode, the bridging instruction only ever points at
     * createRequest for this scenario — never at bookBooking — and a createRequest call is
     * routed to the request handler, not treated as an appointment.
     */
    public function test_mixed_mode_does_not_classify_status_question_as_appointment(): void
    {
        config(['services.gemini.key' => 'fake-key']);
        $salon = $this->createSalon(['appointment' => true, 'request' => true], businessType: 'clinic-healthcare');

        $payload = app(GeminiPayloadBuilder::class)->build($salon, [
            ['role' => 'user', 'content' => 'Cand vor fi analizele mele gata?'],
        ]);
        $instruction = $payload['systemInstruction']['parts'][0]['text'];
        $unknownRuleStart = mb_strpos($instruction, 'Daca nu poti raspunde din informatiile configurate, stabileste intai');
        $unknownRuleSection = mb_substr($instruction, $unknownRuleStart, 1200);
        $this->assertStringNotContainsString('bookBooking', $unknownRuleSection);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiFunctionCallResponse('createRequest', [
                'type' => 'information',
                'priority' => 'normal',
                'description' => 'Verificare stadiu analize pentru pacient',
                'client_name' => 'Maria Ionescu',
                'client_phone' => '0722000111',
            ])),
        ]);

        $response = $this->postJson("/assistant/{$salon->id}/chat", [
            'messages' => [['role' => 'user', 'content' => 'Cand vor fi analizele mele gata?']],
        ]);

        $response->assertOk();
        $conversation = Conversation::query()->latest()->first();
        $this->assertSame('customer_request', $conversation->result_type);
        $this->assertNull($conversation->booking_id);
    }

    /**
     * Requirement 8: website chat and WhatsApp share the same pipeline/instructions — the
     * bridging instruction text is identical regardless of channel.
     */
    public function test_website_and_whatsapp_receive_the_same_request_bridging_instructions(): void
    {
        $salon = $this->createSalon(['appointment' => true, 'request' => true], businessType: 'clinic-healthcare');

        $websitePayload = app(GeminiPayloadBuilder::class)->build($salon, [
            ['role' => 'user', 'content' => 'Cand vor fi analizele mele gata?'],
        ]);

        $whatsappConversation = $salon->conversations()->create([
            'channel' => 'whatsapp',
            'provider' => 'twilio',
            'external_contact_id' => 'whatsapp:+447400606640',
            'external_sender' => 'whatsapp:+40700000000',
            'status' => 'open',
            'intent' => 'inquiry',
            'last_message_at' => now(),
        ]);

        $whatsappPayload = app(GeminiPayloadBuilder::class)->build($salon, [
            ['role' => 'user', 'content' => 'Cand vor fi analizele mele gata?'],
        ], $whatsappConversation, ['channel' => 'whatsapp']);

        $bridgingSentence = 'ofera explicit sa trimiti o solicitare catre echipa folosind functia createRequest';
        $this->assertStringContainsString($bridgingSentence, $websitePayload['systemInstruction']['parts'][0]['text']);
        $this->assertStringContainsString($bridgingSentence, $whatsappPayload['systemInstruction']['parts'][0]['text']);

        $websiteToolNames = collect($websitePayload['tools'][0]['functionDeclarations'])->pluck('name');
        $whatsappToolNames = collect($whatsappPayload['tools'][0]['functionDeclarations'])->pluck('name');
        $this->assertEqualsCanonicalizing($websiteToolNames->all(), $whatsappToolNames->all());
    }

    /**
     * Requirement 9: medical urgency keeps the industry safety instructions intact, and an
     * urgent Request is registered as a Request (never a booking or a substitute for calling
     * emergency services).
     */
    public function test_medical_urgency_keeps_safety_instructions_and_registers_as_request_not_booking(): void
    {
        config(['services.gemini.key' => 'fake-key']);
        $salon = $this->createSalon(['appointment' => true, 'request' => true], businessType: 'clinic-healthcare');

        $payload = app(GeminiPayloadBuilder::class)->build($salon, [
            ['role' => 'user', 'content' => 'Sangerez abundent, ce fac?'],
        ]);
        $instruction = $payload['systemInstruction']['parts'][0]['text'];
        $this->assertStringContainsString('must not diagnose, prescribe or replace emergency medical advice', $instruction);
        $this->assertStringContainsString('direct urgent/emergency cases to call emergency services directly', $instruction);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiFunctionCallResponse('createRequest', [
                'type' => 'information',
                'priority' => 'urgent',
                'description' => 'Pacient raporteaza sangerare, directionat catre echipa',
                'client_name' => 'Radu Stan',
                'client_phone' => '0744000333',
            ])),
        ]);

        $response = $this->postJson("/assistant/{$salon->id}/chat", [
            'messages' => [['role' => 'user', 'content' => 'Sangerez abundent, ce fac?']],
        ]);

        $response->assertOk();
        $customerRequest = CustomerRequest::query()->first();
        $this->assertNotNull($customerRequest);
        $this->assertSame('urgent', $customerRequest->priority->value);
        $conversation = Conversation::query()->latest()->first();
        $this->assertSame('customer_request', $conversation->result_type);
        $this->assertNull($conversation->booking_id);
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
