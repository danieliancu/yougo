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

    public function test_prompt_includes_business_localization_context(): void
    {
        $salon = $this->createSalon([
            'country' => 'GB',
            'currency' => 'GBP',
            'phone_prefix' => '+44',
            'timezone' => 'Europe/London',
            'date_format' => 'dd/mm/yyyy',
        ]);

        $payload = $this->buildPayload($salon);
        $instruction = $payload['systemInstruction']['parts'][0]['text'];

        $this->assertStringContainsString('tara: GB', $instruction);
        $this->assertStringContainsString('moneda: GBP', $instruction);
        $this->assertStringContainsString('prefix telefon: +44', $instruction);
        $this->assertStringContainsString('fus orar: Europe/London', $instruction);
        $this->assertStringContainsString('format data: dd/mm/yyyy', $instruction);
    }

    public function test_prompt_formats_service_prices_with_business_currency(): void
    {
        $salon = $this->createSalon([
            'country' => 'GB',
            'currency' => 'GBP',
        ]);
        $salon->services()->create([
            'name' => 'Consultation',
            'price' => '120',
            'currency' => 'USD',
            'duration' => 30,
        ]);

        $payload = $this->buildPayload($salon);
        $instruction = $payload['systemInstruction']['parts'][0]['text'];

        $this->assertStringContainsString('pret sau tarif: $120', $instruction);
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

    public function test_whatsapp_existing_booking_prompt_does_not_include_website_new_conversation_ui(): void
    {
        [$salon, $conversation] = $this->createConversationWithBooking('whatsapp', ['phone_prefix' => '40']);

        $payload = app(GeminiPayloadBuilder::class)->build($salon, [
            ['role' => 'user', 'content' => 'Vreau sa schimb ora programarii'],
        ], $conversation);
        $instruction = $payload['systemInstruction']['parts'][0]['text'];

        $this->assertStringContainsString('Channel: WhatsApp', $instruction);
        $this->assertStringContainsString('same real WhatsApp thread', $instruction);
        $this->assertStringContainsString('Do not create amendment requests', $instruction);
        $this->assertStringContainsString('provide a phone handoff', $instruction);
        $this->assertStringNotContainsString('+', $instruction);
        $this->assertStringNotContainsString('start a new conversation', $instruction);
        $this->assertStringNotContainsString('open a new chat', $instruction);
        $this->assertStringNotContainsString('sa apese pe +', $instruction);
        $this->assertStringNotContainsString('inceapa o conversatie noua', $instruction);
    }

    public function test_phone_existing_booking_prompt_does_not_inherit_website_chat_ui(): void
    {
        [$salon, $conversation] = $this->createConversationWithBooking('voice', ['phone_prefix' => '40']);

        $payload = app(GeminiPayloadBuilder::class)->build($salon, [
            ['role' => 'user', 'content' => 'Vreau sa schimb ora programarii'],
        ], $conversation);
        $instruction = $payload['systemInstruction']['parts'][0]['text'];

        $this->assertStringContainsString('Channel: Phone', $instruction);
        $this->assertStringContainsString('natural spoken-style interaction', $instruction);
        $this->assertStringNotContainsString('+', $instruction);
        $this->assertStringNotContainsString('start a new conversation', $instruction);
        $this->assertStringNotContainsString('open a new chat', $instruction);
        $this->assertStringNotContainsString('sa apese pe +', $instruction);
    }

    public function test_payload_includes_known_contact_reuse_rules(): void
    {
        $salon = $this->createSalon();

        $payload = app(GeminiPayloadBuilder::class)->build($salon, [
            ['role' => 'assistant', 'content' => 'Vrei să folosim și pentru această programare datele folosite anterior: Daniel, 07123 456789?'],
            ['role' => 'user', 'content' => 'Da'],
        ], knownContact: [
            'name' => 'Daniel',
            'phone' => '07123 456789',
        ]);
        $instruction = $payload['systemInstruction']['parts'][0]['text'];

        $this->assertStringContainsString('Există date de contact folosite anterior pentru acest vizitator: Daniel, 07123 456789.', $instruction);
        $this->assertStringContainsString('Nu le folosi automat.', $instruction);
        $this->assertStringContainsString('Do not use them silently.', $instruction);
        $this->assertStringContainsString('If they confirm, you may use them as client_name and client_phone for bookBooking.', $instruction);
        $this->assertStringContainsString('Daca utilizatorul confirma ca vrea sa le refoloseasca, nu mai cere nume sau telefon', $instruction);
        $this->assertStringContainsString('Daca utilizatorul refuza sau ignora intrebarea, cere date de contact noi', $instruction);
    }

    public function test_whatsapp_prompt_uses_known_phone_without_asking_again(): void
    {
        $salon = $this->createSalon();
        $conversation = $salon->conversations()->create([
            'channel' => 'whatsapp',
            'provider' => 'twilio',
            'external_contact_id' => 'whatsapp:+447400606640',
            'external_sender' => 'whatsapp:+40700000000',
            'status' => 'open',
            'intent' => 'booking',
            'last_message_at' => now(),
        ]);

        $payload = app(GeminiPayloadBuilder::class)->build($salon, [
            ['role' => 'user', 'content' => 'Vreau o programare'],
        ], $conversation, [
            'phone' => 'whatsapp:+447400606640',
            'channel' => 'whatsapp',
        ]);
        $instruction = $payload['systemInstruction']['parts'][0]['text'];
        $required = $payload['tools'][0]['functionDeclarations'][0]['parameters']['required'];

        $this->assertStringContainsString('The customer phone number is already known from WhatsApp: +447400606640.', $instruction);
        $this->assertStringContainsString('Do not ask for it again unless it is missing or invalid.', $instruction);
        $this->assertNotContains('client_phone', $required);
    }

    public function test_whatsapp_new_conversation_includes_recent_confirmed_booking_context_by_phone(): void
    {
        $salon = $this->createSalon();
        $service = $salon->services()->create([
            'name' => 'Tuns si coafat',
            'price' => '150',
            'duration' => 60,
        ]);
        $salon->bookings()->create([
            'service_id' => $service->id,
            'client_name' => 'Maria Client',
            'client_phone' => '+40711111111',
            'date' => '2026-06-02',
            'time' => '17:00',
            'status' => 'confirmed',
            'source' => 'ai_assistant',
        ]);
        $conversation = $salon->conversations()->create([
            'channel' => 'whatsapp',
            'provider' => 'twilio',
            'external_contact_id' => 'whatsapp:+40711111111',
            'external_sender' => 'whatsapp:+40700000000',
            'contact_phone' => 'whatsapp:+40711111111',
            'status' => 'open',
            'intent' => 'inquiry',
            'last_message_at' => now(),
        ]);

        $payload = app(GeminiPayloadBuilder::class)->build($salon, [
            ['role' => 'user', 'content' => 'a fost confirmata, da?'],
        ], $conversation, [
            'phone' => 'whatsapp:+40711111111',
            'channel' => 'whatsapp',
        ]);
        $instruction = $payload['systemInstruction']['parts'][0]['text'];

        $this->assertStringContainsString('Recent customer booking context:', $instruction);
        $this->assertStringContainsString('status: confirmed.', $instruction);
        $this->assertStringContainsString('data: 2026-06-02.', $instruction);
        $this->assertStringContainsString('ora: 17:00.', $instruction);
        $this->assertStringContainsString('serviciu: Tuns si coafat.', $instruction);
        $this->assertStringContainsString('Do not answer generically that AI-created bookings are pending', $instruction);
        $this->assertStringNotContainsString('Programarile create de AI raman pending si trebuie confirmate de echipa.', $instruction);
        $this->assertNull($conversation->refresh()->booking_id);
    }

    public function test_whatsapp_recent_pending_cancelled_and_completed_context_statuses_are_included(): void
    {
        foreach (['pending', 'cancelled', 'completed'] as $status) {
            $salon = $this->createSalon();
            $phone = '+4071111111'.array_search($status, ['pending', 'cancelled', 'completed'], true);
            $salon->bookings()->create([
                'client_name' => 'Maria Client',
                'client_phone' => $phone,
                'date' => '2026-06-02',
                'time' => '17:00',
                'status' => $status,
                'source' => 'ai_assistant',
            ]);
            $conversation = $salon->conversations()->create([
                'channel' => 'whatsapp',
                'provider' => 'twilio',
                'external_contact_id' => 'whatsapp:'.$phone,
                'external_sender' => 'whatsapp:+40700000000',
                'contact_phone' => 'whatsapp:'.$phone,
                'status' => 'open',
                'intent' => 'inquiry',
                'last_message_at' => now(),
            ]);

            $payload = app(GeminiPayloadBuilder::class)->build($salon, [
                ['role' => 'user', 'content' => 'ce status are programarea?'],
            ], $conversation, [
                'phone' => 'whatsapp:'.$phone,
                'channel' => 'whatsapp',
            ]);
            $instruction = $payload['systemInstruction']['parts'][0]['text'];

            $this->assertStringContainsString("status: {$status}.", $instruction);
            $this->assertStringContainsString('Recent customer booking context:', $instruction);
        }
    }

    public function test_recent_booking_context_does_not_cross_salon_or_phone_boundaries(): void
    {
        $salon = $this->createSalon();
        $otherSalon = $this->createSalon();
        $otherSalon->bookings()->create([
            'client_name' => 'Other Salon Client',
            'client_phone' => '+40711111111',
            'date' => '2026-06-02',
            'time' => '17:00',
            'status' => 'confirmed',
        ]);
        $salon->bookings()->create([
            'client_name' => 'Wrong Phone Client',
            'client_phone' => '+40722222222',
            'date' => '2026-06-02',
            'time' => '18:00',
            'status' => 'confirmed',
        ]);
        $conversation = $salon->conversations()->create([
            'channel' => 'whatsapp',
            'provider' => 'twilio',
            'external_contact_id' => 'whatsapp:+40711111111',
            'external_sender' => 'whatsapp:+40700000000',
            'contact_phone' => 'whatsapp:+40711111111',
            'status' => 'open',
            'intent' => 'inquiry',
            'last_message_at' => now(),
        ]);

        $payload = app(GeminiPayloadBuilder::class)->build($salon, [
            ['role' => 'user', 'content' => 'a fost confirmata?'],
        ], $conversation, [
            'phone' => 'whatsapp:+40711111111',
            'channel' => 'whatsapp',
        ]);
        $instruction = $payload['systemInstruction']['parts'][0]['text'];

        $this->assertStringNotContainsString('Recent customer booking context:', $instruction);
        $this->assertStringNotContainsString('Other Salon Client', $instruction);
        $this->assertStringNotContainsString('Wrong Phone Client', $instruction);
    }

    public function test_current_conversation_booking_takes_priority_over_recent_context(): void
    {
        $salon = $this->createSalon();
        $oldBooking = $salon->bookings()->create([
            'client_name' => 'Old Booking',
            'client_phone' => '+40711111111',
            'date' => '2026-06-02',
            'time' => '17:00',
            'status' => 'confirmed',
        ]);
        $currentBooking = $salon->bookings()->create([
            'client_name' => 'Current Booking',
            'client_phone' => '+40711111111',
            'date' => '2026-06-03',
            'time' => '10:00',
            'status' => 'pending',
        ]);
        $conversation = $salon->conversations()->create([
            'booking_id' => $currentBooking->id,
            'channel' => 'whatsapp',
            'provider' => 'twilio',
            'external_contact_id' => 'whatsapp:+40711111111',
            'external_sender' => 'whatsapp:+40700000000',
            'contact_phone' => 'whatsapp:+40711111111',
            'status' => 'open',
            'intent' => 'booking',
            'last_message_at' => now(),
        ]);

        $payload = app(GeminiPayloadBuilder::class)->build($salon, [
            ['role' => 'user', 'content' => 'ce status are programarea?'],
        ], $conversation, [
            'phone' => 'whatsapp:+40711111111',
            'channel' => 'whatsapp',
        ]);
        $instruction = $payload['systemInstruction']['parts'][0]['text'];

        $this->assertStringContainsString('Aceasta conversatie are deja o programare in baza de date.', $instruction);
        $this->assertStringContainsString('status curent: pending.', $instruction);
        $this->assertStringNotContainsString('Recent customer booking context:', $instruction);
        $this->assertSame($currentBooking->id, $conversation->refresh()->booking_id);
        $this->assertNotSame($oldBooking->id, $conversation->booking_id);
    }

    public function test_website_chat_still_requires_phone_when_configured(): void
    {
        $salon = $this->createSalon(['ai_collect_phone' => true]);
        $salon->bookings()->create([
            'client_name' => 'Website Client',
            'client_phone' => '+40711111111',
            'date' => '2026-06-02',
            'time' => '17:00',
            'status' => 'confirmed',
        ]);
        $conversation = $salon->conversations()->create([
            'channel' => 'chat',
            'contact_phone' => '+40711111111',
            'status' => 'open',
            'intent' => 'inquiry',
            'last_message_at' => now(),
        ]);

        $payload = app(GeminiPayloadBuilder::class)->build($salon, [
            ['role' => 'user', 'content' => 'Vreau o programare'],
        ], $conversation, [
            'phone' => '+40711111111',
            'channel' => 'chat',
        ]);
        $instruction = $payload['systemInstruction']['parts'][0]['text'];
        $required = $payload['tools'][0]['functionDeclarations'][0]['parameters']['required'];

        $this->assertContains('client_phone', $required);
        $this->assertStringContainsString('Cere telefonul clientului inainte de creare.', $instruction);
        $this->assertStringNotContainsString('already known from WhatsApp', $instruction);
        $this->assertStringNotContainsString('Recent customer booking context:', $instruction);
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

    private function createConversationWithBooking(string $channel, array $salonAttributes = []): array
    {
        $salon = $this->createSalon($salonAttributes);
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
            'date' => '2026-06-30',
            'time' => '10:00',
            'status' => 'confirmed',
            'source' => 'ai_assistant',
        ]);
        $conversation = $salon->conversations()->create([
            'booking_id' => $booking->id,
            'channel' => $channel,
            'status' => 'completed',
            'intent' => 'booking',
            'summary' => 'Booking created.',
            'last_message_at' => now(),
        ]);

        return [$salon, $conversation];
    }
}
