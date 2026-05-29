<?php

namespace Tests\Feature;

use App\Models\ConversationMessage;
use App\Models\Salon;
use App\Models\User;
use App\Models\WhatsappIntegration;
use App\Mail\BookingChangeRequestMail;
use App\Mail\NewAiBookingMail;
use App\Services\WhatsApp\TwilioWhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Twilio\Security\RequestValidator;
use Tests\TestCase;

class WhatsappIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_activation_requires_auth(): void
    {
        $this->postJson('/dashboard/whatsapp/request-activation', [
            'requested_number' => '+40711111111',
        ])->assertUnauthorized();
    }

    public function test_request_activation_requires_salon(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/dashboard/whatsapp/request-activation', [
                'requested_number' => '+40711111111',
            ])
            ->assertForbidden();
    }

    public function test_request_activation_requires_whatsapp_plan(): void
    {
        [$salon, $user] = $this->createSalonWithUser(['plan' => 'website_chat']);

        $this->actingAs($user)
            ->postJson('/dashboard/whatsapp/request-activation', [
                'requested_number' => '+40711111111',
            ])
            ->assertForbidden();

        $this->assertNull($salon->whatsappIntegration);
    }

    public function test_request_activation_stores_requested_number_and_status(): void
    {
        [$salon, $user] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);

        $this->actingAs($user)
            ->postJson('/dashboard/whatsapp/request-activation', [
                'requested_number' => '+40 711 111 111',
            ])
            ->assertOk()
            ->assertJsonPath('integration.requested_number', '+40711111111')
            ->assertJsonPath('integration.status', 'requested');

        $integration = $salon->refresh()->whatsappIntegration;
        $this->assertSame('twilio', $integration->provider);
        $this->assertSame('+40711111111', $integration->requested_number);
        $this->assertSame('requested', $integration->status);
        $this->assertNotNull($integration->requested_at);
        $this->assertNull($integration->twilio_sender);
    }

    public function test_toggle_requires_active_integration_and_whatsapp_plan(): void
    {
        [$inactiveSalon, $inactiveUser] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);
        $inactiveSalon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => 'requested',
        ]);

        $this->actingAs($inactiveUser)
            ->patchJson('/dashboard/whatsapp/toggle', ['ai_enabled' => true])
            ->assertStatus(422);

        [$noPlanSalon, $noPlanUser] = $this->createSalonWithUser(['plan' => 'website_chat']);
        $noPlanSalon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => 'active',
            'twilio_sender' => 'whatsapp:+40700000000',
        ]);

        $this->actingAs($noPlanUser)
            ->patchJson('/dashboard/whatsapp/toggle', ['ai_enabled' => true])
            ->assertForbidden();
    }

    public function test_toggle_updates_ai_enabled_for_active_integration(): void
    {
        [$salon, $user] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);
        $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => 'active',
            'twilio_sender' => 'whatsapp:+40700000000',
            'ai_enabled' => false,
        ]);

        $this->actingAs($user)
            ->patchJson('/dashboard/whatsapp/toggle', ['ai_enabled' => true])
            ->assertOk()
            ->assertJsonPath('integration.ai_enabled', true);

        $this->assertTrue($salon->refresh()->whatsappIntegration->ai_enabled);
    }

    public function test_test_message_requires_active_integration_and_sender(): void
    {
        [$salon, $user] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);
        $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => 'requested',
        ]);

        $this->actingAs($user)
            ->postJson('/dashboard/whatsapp/test-message', [
                'to' => '+40711111111',
                'message' => 'Test',
            ])
            ->assertStatus(422);

        $salon->whatsappIntegration()->update(['status' => 'active']);

        $this->actingAs($user)
            ->postJson('/dashboard/whatsapp/test-message', [
                'to' => '+40711111111',
                'message' => 'Test',
            ])
            ->assertStatus(422);
    }

    public function test_test_message_sends_through_twilio_service_and_stores_outbound_message(): void
    {
        [$salon, $user] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);
        $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => 'active',
            'twilio_sender' => 'whatsapp:+40700000000',
        ]);
        $this->fakeTwilioService();

        $this->actingAs($user)
            ->postJson('/dashboard/whatsapp/test-message', [
                'to' => '+40711111111',
                'message' => 'Test from YouGo',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('provider.sid', 'SM_TEST');

        $message = ConversationMessage::query()->first();
        $this->assertSame('assistant', $message->role);
        $this->assertSame('outbound', $message->direction);
        $this->assertSame('SM_TEST', $message->provider_message_id);
        $this->assertSame(1, $salon->usageEvents()->where('event_type', 'whatsapp_message_outbound')->count());
    }

    public function test_webhook_ignores_unknown_sender_safely(): void
    {
        config(['twilio.validate_signature' => false]);

        $this->post('/twilio/whatsapp/webhook', $this->twilioPayload([
            'To' => 'whatsapp:+40799999999',
        ]))
            ->assertOk()
            ->assertHeader('content-type', 'text/xml; charset=UTF-8')
            ->assertSee('<Response></Response>', false);

        $this->assertDatabaseCount('conversation_messages', 0);
    }

    public function test_webhook_maps_to_number_and_stores_inbound_whatsapp_message(): void
    {
        config(['twilio.validate_signature' => false]);
        [$salon] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);
        $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => 'active',
            'twilio_sender' => 'whatsapp:+40700000000',
            'ai_enabled' => false,
        ]);

        $this->post('/twilio/whatsapp/webhook', $this->twilioPayload())
            ->assertOk()
            ->assertSee('<Response></Response>', false);

        $conversation = $salon->refresh()->conversations()->first();
        $message = $conversation->messages()->first();

        $this->assertSame('whatsapp', $conversation->channel);
        $this->assertSame('twilio', $conversation->provider);
        $this->assertSame('whatsapp:+40711111111', $conversation->external_contact_id);
        $this->assertSame('Maria Client', $conversation->contact_name);
        $this->assertSame('user', $message->role);
        $this->assertSame('inbound', $message->direction);
        $this->assertSame('SM_INBOUND', $message->provider_message_id);
        $this->assertSame('Buna', $message->content);
        $this->assertSame(1, $salon->usageEvents()->where('event_type', 'whatsapp_conversation')->count());
        $this->assertSame(1, $salon->usageEvents()->where('event_type', 'whatsapp_message_inbound')->count());
    }

    public function test_webhook_with_ai_enabled_sends_and_saves_ai_reply(): void
    {
        config([
            'twilio.validate_signature' => false,
            'services.gemini.key' => 'test-key',
        ]);
        [$salon] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);
        $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => 'active',
            'twilio_sender' => 'whatsapp:+40700000000',
            'ai_enabled' => true,
        ]);
        $this->fakeGeminiText('Sigur, cu ce serviciu te pot ajuta?');
        $this->fakeTwilioService();

        $this->post('/twilio/whatsapp/webhook', $this->twilioPayload())->assertOk();

        $conversation = $salon->refresh()->conversations()->firstOrFail();
        $this->assertDatabaseHas('conversation_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'direction' => 'outbound',
            'provider' => 'twilio',
            'provider_message_id' => 'SM_TEST',
            'content' => 'Sigur, cu ce serviciu te pot ajuta?',
        ]);
        $this->assertSame(1, $salon->usageEvents()->where('event_type', 'whatsapp_message_outbound')->count());
        $this->assertSame(1, $salon->usageEvents()->where('event_type', 'whatsapp_ai_reply')->count());
    }

    public function test_webhook_duplicate_message_does_not_call_ai_twice(): void
    {
        config([
            'twilio.validate_signature' => false,
            'services.gemini.key' => 'test-key',
        ]);
        [$salon] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);
        $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => 'active',
            'twilio_sender' => 'whatsapp:+40700000000',
            'ai_enabled' => true,
        ]);
        $this->fakeGeminiText('Primul raspuns');
        $this->fakeTwilioService();

        $payload = $this->twilioPayload();
        $this->post('/twilio/whatsapp/webhook', $payload)->assertOk();
        $this->post('/twilio/whatsapp/webhook', $payload)->assertOk();

        $this->assertSame(2, ConversationMessage::query()->count());
        Http::assertSentCount(1);
        $this->assertSame(1, $salon->usageEvents()->where('event_type', 'whatsapp_ai_reply')->count());
    }

    public function test_webhook_does_not_reply_when_plan_lacks_whatsapp_ai(): void
    {
        config([
            'twilio.validate_signature' => false,
            'services.gemini.key' => 'test-key',
        ]);
        [$salon] = $this->createSalonWithUser(['plan' => 'website_chat']);
        $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => 'active',
            'twilio_sender' => 'whatsapp:+40700000000',
            'ai_enabled' => true,
        ]);
        $this->fakeGeminiText('Nu ar trebui trimis');
        $this->fakeTwilioService();

        $this->post('/twilio/whatsapp/webhook', $this->twilioPayload())->assertOk();

        $this->assertDatabaseCount('conversation_messages', 0);
        Http::assertSentCount(0);
    }

    public function test_webhook_does_not_reply_when_integration_inactive(): void
    {
        config([
            'twilio.validate_signature' => false,
            'services.gemini.key' => 'test-key',
        ]);
        [$salon] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);
        $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => 'requested',
            'twilio_sender' => 'whatsapp:+40700000000',
            'ai_enabled' => true,
        ]);
        $this->fakeGeminiText('Nu ar trebui trimis');
        $this->fakeTwilioService();

        $this->post('/twilio/whatsapp/webhook', $this->twilioPayload())->assertOk();

        $this->assertDatabaseCount('conversation_messages', 0);
        Http::assertSentCount(0);
    }

    public function test_webhook_empty_body_saves_inbound_without_ai_reply(): void
    {
        config([
            'twilio.validate_signature' => false,
            'services.gemini.key' => 'test-key',
        ]);
        [$salon] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);
        $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => 'active',
            'twilio_sender' => 'whatsapp:+40700000000',
            'ai_enabled' => true,
        ]);
        $this->fakeGeminiText('Nu ar trebui trimis');
        $this->fakeTwilioService();

        $this->post('/twilio/whatsapp/webhook', $this->twilioPayload(['Body' => '']))->assertOk();

        $this->assertDatabaseHas('conversation_messages', [
            'role' => 'user',
            'direction' => 'inbound',
            'content' => '',
        ]);
        $this->assertSame(1, ConversationMessage::query()->count());
        Http::assertSentCount(0);
    }

    public function test_webhook_media_only_sends_text_only_fallback(): void
    {
        config(['twilio.validate_signature' => false]);
        [$salon] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);
        $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => 'active',
            'twilio_sender' => 'whatsapp:+40700000000',
            'ai_enabled' => true,
        ]);
        $this->fakeTwilioService();

        $this->post('/twilio/whatsapp/webhook', $this->twilioPayload(['Body' => '', 'NumMedia' => '1']))->assertOk();

        $this->assertDatabaseHas('conversation_messages', [
            'role' => 'assistant',
            'direction' => 'outbound',
            'content' => 'Momentan pot procesa doar mesaje text pe WhatsApp.',
        ]);
    }

    public function test_webhook_ai_failure_saves_fallback_reply(): void
    {
        config([
            'twilio.validate_signature' => false,
            'services.gemini.key' => 'test-key',
        ]);
        [$salon] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);
        $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => 'active',
            'twilio_sender' => 'whatsapp:+40700000000',
            'ai_enabled' => true,
        ]);
        Http::fake(['*' => Http::response(['error' => ['message' => 'bad gateway']], 502)]);
        $this->fakeTwilioService();

        $this->post('/twilio/whatsapp/webhook', $this->twilioPayload())->assertOk();

        $this->assertDatabaseHas('conversation_messages', [
            'role' => 'assistant',
            'direction' => 'outbound',
            'content' => 'Imi pare rau, nu pot raspunde automat acum. Te rugam sa incerci din nou mai tarziu sau sa contactezi direct businessul.',
        ]);
    }

    public function test_webhook_twilio_send_failure_is_saved_safely(): void
    {
        config([
            'twilio.validate_signature' => false,
            'services.gemini.key' => 'test-key',
        ]);
        [$salon] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);
        $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => 'active',
            'twilio_sender' => 'whatsapp:+40700000000',
            'ai_enabled' => true,
        ]);
        $this->fakeGeminiText('Raspuns AI');
        $this->fakeTwilioService(fail: true);

        $this->post('/twilio/whatsapp/webhook', $this->twilioPayload())->assertOk();

        $message = ConversationMessage::query()->where('direction', 'outbound')->firstOrFail();
        $this->assertSame('Raspuns AI', $message->content);
        $this->assertNull($message->provider_message_id);
        $this->assertSame('failed', $message->metadata['status']);
        $this->assertSame('twilio_send_failed', $message->metadata['failure']);
    }

    public function test_webhook_booking_creation_uses_whatsapp_source_and_notifications(): void
    {
        config([
            'twilio.validate_signature' => false,
            'services.gemini.key' => 'test-key',
        ]);
        Mail::fake();
        [$salon] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);
        $salon->update([
            'timezone' => 'Europe/Bucharest',
            'email_notifications' => true,
            'notification_email' => 'owner@example.com',
        ]);
        $location = $salon->locations()->create([
            'name' => 'Central',
            'address' => 'Main Street',
            'hours' => ['wed' => '10:00 - 18:00'],
        ]);
        $service = $salon->services()->create([
            'name' => 'Tuns',
            'price' => '100',
            'duration' => 30,
            'location_ids' => [$location->id],
        ]);
        $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => 'active',
            'twilio_sender' => 'whatsapp:+40700000000',
            'ai_enabled' => true,
        ]);
        $this->fakeGeminiFunctionCall('bookBooking', [
            'client_name' => 'Maria Client',
            'client_phone' => '+40711111111',
            'location_id' => (string) $location->id,
            'service_id' => (string) $service->id,
            'date' => '2026-06-03',
            'time' => '10:00',
        ]);
        $this->fakeTwilioService();

        $this->post('/twilio/whatsapp/webhook', $this->twilioPayload())->assertOk();

        $booking = $salon->bookings()->firstOrFail();
        $this->assertSame('whatsapp', $booking->source);
        $this->assertNotNull($booking->notification_sent_at);
        Mail::assertSent(NewAiBookingMail::class);
    }

    public function test_whatsapp_post_booking_change_request_is_recorded_without_automatic_booking_change(): void
    {
        config([
            'twilio.validate_signature' => false,
            'services.gemini.key' => 'test-key',
        ]);
        Mail::fake();
        [$salon] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);
        $salon->update([
            'notification_email' => 'owner@example.com',
            'booking_confirmations' => true,
        ]);
        $location = $salon->locations()->create([
            'name' => 'Central',
            'address' => 'Main Street',
            'hours' => ['wed' => '10:00 - 18:00'],
        ]);
        $service = $salon->services()->create([
            'name' => 'Tuns',
            'price' => '100',
            'duration' => 30,
            'location_ids' => [$location->id],
        ]);
        $booking = $salon->bookings()->create([
            'location_id' => $location->id,
            'service_id' => $service->id,
            'client_name' => 'Maria Client',
            'client_phone' => '+40711111111',
            'date' => '2026-06-03',
            'time' => '10:00',
            'status' => 'confirmed',
            'source' => 'whatsapp',
        ]);
        $conversation = $salon->conversations()->create([
            'booking_id' => $booking->id,
            'channel' => 'whatsapp',
            'provider' => 'twilio',
            'external_contact_id' => 'whatsapp:+40711111111',
            'external_sender' => 'whatsapp:+40700000000',
            'contact_name' => 'Maria Client',
            'contact_phone' => 'whatsapp:+40711111111',
            'status' => 'completed',
            'intent' => 'booking',
            'summary' => 'Booking created.',
            'last_message_at' => now(),
        ]);
        $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => 'active',
            'twilio_sender' => 'whatsapp:+40700000000',
            'ai_enabled' => true,
        ]);
        $this->fakeGeminiText('Sigur. Am transmis cererea catre echipa pentru confirmare.');
        $this->fakeTwilioService();

        $this->post('/twilio/whatsapp/webhook', $this->twilioPayload([
            'Body' => 'Vreau sa schimb ora programarii la 12:00',
            'MessageSid' => 'SM_CHANGE',
        ]))->assertOk();

        $conversation->refresh();
        $booking->refresh();
        $request = $conversation->metadata['booking_change_requests'][0] ?? null;

        $this->assertNotNull($request);
        $this->assertSame('reschedule', $request['type']);
        $this->assertSame('whatsapp', $request['source']);
        $this->assertSame('pending', $request['status']);
        $this->assertSame('Vreau sa schimb ora programarii la 12:00', $request['requested_text']);
        $this->assertSame('confirmed', $request['previous_booking_status']);
        $this->assertNotEmpty($request['notified_at'] ?? null);
        $this->assertSame('pending', $booking->status);
        $this->assertSame('10:00', $booking->time);
        $this->assertDatabaseHas('conversation_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'direction' => 'outbound',
            'content' => 'Sigur. Am transmis cererea catre echipa pentru confirmare.',
        ]);
        Mail::assertSent(BookingChangeRequestMail::class);
        $this->assertStringNotContainsString('+', ConversationMessage::query()->where('direction', 'outbound')->latest()->firstOrFail()->content);
        $this->assertStringNotContainsString('conversatie noua', ConversationMessage::query()->where('direction', 'outbound')->latest()->firstOrFail()->content);
    }

    public function test_whatsapp_existing_booking_tool_call_records_pending_request_without_plus_instruction(): void
    {
        config([
            'twilio.validate_signature' => false,
            'services.gemini.key' => 'test-key',
        ]);
        [$salon] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);
        $location = $salon->locations()->create([
            'name' => 'Central',
            'address' => 'Main Street',
            'hours' => ['wed' => '10:00 - 18:00'],
        ]);
        $service = $salon->services()->create([
            'name' => 'Tuns',
            'price' => '100',
            'duration' => 30,
            'location_ids' => [$location->id],
        ]);
        $booking = $salon->bookings()->create([
            'location_id' => $location->id,
            'service_id' => $service->id,
            'client_name' => 'Maria Client',
            'client_phone' => '+40711111111',
            'date' => '2026-06-03',
            'time' => '10:00',
            'status' => 'confirmed',
            'source' => 'whatsapp',
        ]);
        $conversation = $salon->conversations()->create([
            'booking_id' => $booking->id,
            'channel' => 'whatsapp',
            'provider' => 'twilio',
            'external_contact_id' => 'whatsapp:+40711111111',
            'external_sender' => 'whatsapp:+40700000000',
            'contact_name' => 'Maria Client',
            'contact_phone' => 'whatsapp:+40711111111',
            'status' => 'completed',
            'intent' => 'booking',
            'summary' => 'Booking created.',
            'last_message_at' => now(),
        ]);
        $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => 'active',
            'twilio_sender' => 'whatsapp:+40700000000',
            'ai_enabled' => true,
        ]);
        $this->fakeGeminiFunctionCall('bookBooking', [
            'client_name' => 'Maria Client',
            'client_phone' => '+40711111111',
            'location_id' => (string) $location->id,
            'service_id' => (string) $service->id,
            'date' => '2026-06-03',
            'time' => '12:00',
        ]);
        $this->fakeTwilioService();

        $this->post('/twilio/whatsapp/webhook', $this->twilioPayload([
            'Body' => 'Vreau si un alt serviciu la 12:00',
            'MessageSid' => 'SM_NEW_SERVICE',
        ]))->assertOk();

        $conversation->refresh();
        $booking->refresh();
        $outbound = ConversationMessage::query()->where('direction', 'outbound')->latest()->firstOrFail();

        $this->assertSame('change_service', $conversation->metadata['booking_change_requests'][0]['type']);
        $this->assertSame('pending', $booking->status);
        $this->assertSame('10:00', $booking->time);
        $this->assertSame('Am transmis cererea catre echipa pentru confirmare.', $outbound->content);
        $this->assertStringNotContainsString('+', $outbound->content);
        $this->assertStringNotContainsString('conversatie noua', $outbound->content);
    }

    public function test_whatsapp_reschedule_request_checks_availability_and_tells_client_when_unavailable(): void
    {
        config([
            'twilio.validate_signature' => false,
            'services.gemini.key' => 'test-key',
        ]);
        Mail::fake();
        [$salon] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);
        $salon->update([
            'notification_email' => 'owner@example.com',
            'booking_confirmations' => true,
            'timezone' => 'Europe/Bucharest',
        ]);
        $location = $salon->locations()->create([
            'name' => 'Central',
            'address' => 'Main Street',
            'hours' => ['wed' => '09:00 - 18:00'],
        ]);
        $service = $salon->services()->create([
            'name' => 'Tuns',
            'price' => '100',
            'duration' => 30,
            'location_ids' => [$location->id],
        ]);
        $booking = $salon->bookings()->create([
            'location_id' => $location->id,
            'service_id' => $service->id,
            'client_name' => 'Maria Client',
            'client_phone' => '+40711111111',
            'date' => '2026-06-03',
            'time' => '10:00',
            'status' => 'confirmed',
            'source' => 'whatsapp',
        ]);
        $salon->bookings()->create([
            'location_id' => $location->id,
            'service_id' => $service->id,
            'client_name' => 'Alt Client',
            'client_phone' => '+40722222222',
            'date' => '2026-06-03',
            'time' => '12:00',
            'status' => 'confirmed',
            'source' => 'manual',
        ]);
        $conversation = $salon->conversations()->create([
            'booking_id' => $booking->id,
            'channel' => 'whatsapp',
            'provider' => 'twilio',
            'external_contact_id' => 'whatsapp:+40711111111',
            'external_sender' => 'whatsapp:+40700000000',
            'contact_name' => 'Maria Client',
            'contact_phone' => 'whatsapp:+40711111111',
            'status' => 'completed',
            'intent' => 'booking',
            'summary' => 'Booking created.',
            'last_message_at' => now(),
        ]);
        $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => 'active',
            'twilio_sender' => 'whatsapp:+40700000000',
            'ai_enabled' => true,
        ]);
        $this->fakeGeminiFunctionCall('checkAvailability', [
            'location_id' => (string) $location->id,
            'service_id' => (string) $service->id,
            'date' => '2026-06-03',
            'preferred_time' => '12:00',
        ]);
        $this->fakeTwilioService();

        $this->post('/twilio/whatsapp/webhook', $this->twilioPayload([
            'Body' => 'Vreau sa schimb ora programarii pe 2026-06-03 la 12:00',
            'MessageSid' => 'SM_RESCHEDULE_UNAVAILABLE',
        ]))->assertOk();

        $conversation->refresh();
        $booking->refresh();
        $request = $conversation->metadata['booking_change_requests'][0] ?? null;
        $outbound = ConversationMessage::query()->where('direction', 'outbound')->latest()->firstOrFail();

        $this->assertSame('pending', $booking->status);
        $this->assertSame('10:00', $booking->time);
        $this->assertSame('reschedule', $request['type']);
        $this->assertTrue($request['availability_checked']);
        $this->assertSame('unavailable', $request['availability_status']);
        $this->assertSame('2026-06-03', $request['requested_date']);
        $this->assertSame('12:00', $request['requested_time']);
        $this->assertStringContainsString('nu este disponibila', $outbound->content);
        Mail::assertSent(BookingChangeRequestMail::class);
    }

    public function test_webhook_whatsapp_monthly_limit_sends_limit_fallback_without_ai(): void
    {
        config([
            'twilio.validate_signature' => false,
            'services.gemini.key' => 'test-key',
            'yougo_plans.chat_whatsapp.monthly_whatsapp_messages' => 1,
        ]);
        [$salon] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);
        $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => 'active',
            'twilio_sender' => 'whatsapp:+40700000000',
            'ai_enabled' => true,
        ]);
        $this->fakeGeminiText('Nu ar trebui trimis');
        $this->fakeTwilioService();

        $this->post('/twilio/whatsapp/webhook', $this->twilioPayload())->assertOk();

        $this->assertDatabaseHas('conversation_messages', [
            'role' => 'assistant',
            'direction' => 'outbound',
            'content' => 'Momentan nu pot raspunde automat pe WhatsApp. Te rugam sa contactezi direct businessul.',
        ]);
        Http::assertSentCount(0);
    }

    public function test_webhook_deduplicates_message_sid(): void
    {
        config(['twilio.validate_signature' => false]);
        [$salon] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);
        $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => 'active',
            'twilio_sender' => 'whatsapp:+40700000000',
        ]);

        $payload = $this->twilioPayload();
        $this->post('/twilio/whatsapp/webhook', $payload)->assertOk();
        $this->post('/twilio/whatsapp/webhook', $payload)->assertOk();

        $this->assertDatabaseCount('conversation_messages', 1);
    }

    public function test_webhook_rejects_invalid_signature_when_validation_enabled(): void
    {
        config([
            'twilio.validate_signature' => true,
            'twilio.auth_token' => 'secret',
        ]);

        $this->withHeader('X-Twilio-Signature', 'invalid')
            ->post('/twilio/whatsapp/webhook', $this->twilioPayload())
            ->assertForbidden();
    }

    public function test_webhook_accepts_valid_twilio_signature(): void
    {
        config([
            'twilio.validate_signature' => true,
            'twilio.auth_token' => 'secret',
        ]);
        [$salon] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);
        $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => 'active',
            'twilio_sender' => 'whatsapp:+40700000000',
        ]);
        $payload = $this->twilioPayload();
        $signature = (new RequestValidator('secret'))->computeSignature('http://localhost/twilio/whatsapp/webhook', $payload);

        $this->withHeader('X-Twilio-Signature', $signature)
            ->post('/twilio/whatsapp/webhook', $payload)
            ->assertOk();

        $this->assertDatabaseHas('conversation_messages', [
            'provider_message_id' => 'SM_INBOUND',
        ]);
    }

    public function test_manual_activation_command_activates_integration(): void
    {
        [$salon] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);
        $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => 'requested',
            'requested_number' => '+40700000000',
        ]);

        $this->artisan('yougo:whatsapp-activate', [
            'salon_id' => $salon->id,
            'twilio_sender' => '+40700000000',
        ])->assertSuccessful();

        $integration = $salon->refresh()->whatsappIntegration;
        $this->assertSame('active', $integration->status);
        $this->assertSame('whatsapp:+40700000000', $integration->twilio_sender);
        $this->assertSame('+40700000000', $integration->display_number);
        $this->assertNotNull($integration->activated_at);
    }

    public function test_dashboard_whatsapp_settings_source_contains_foundation_ui(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Dashboard/Index.tsx'));
        $translations = file_get_contents(resource_path('js/i18n.ts'));

        foreach ([
            'WhatsAppSettings',
            '/dashboard/whatsapp/request-activation',
            '/dashboard/whatsapp/toggle',
            '/dashboard/whatsapp/test-message',
            'whatsappRequiresUpgrade',
            'whatsappStatusActive',
        ] as $needle) {
            $this->assertStringContainsString($needle, $source.$translations);
        }
    }

    public function test_dashboard_whatsapp_conversation_ui_source_contains_channel_specific_polish(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Dashboard/Index.tsx'));
        $translations = file_get_contents(resource_path('js/i18n.ts'));

        foreach ([
            'whatsappConversationTitle',
            'chatConversationTitle',
            'phoneConversationTitle',
            'clientBadge',
            'aiBadge',
            'yougoBadge',
            'whatsappAiCardTitle',
            'conversationChannel',
            'provider',
            'Conversație WhatsApp',
            'WhatsApp conversation',
        ] as $needle) {
            $this->assertStringContainsString($needle, $translations);
        }

        foreach ([
            'conversationChannelTitle(selected, t)',
            'cleanWhatsappAddress',
            "replace(/^whatsapp:/i, '')",
            'isPhoneConversation(selected) &&',
            'isWhatsappConversation(selected) &&',
            'BookingStatusCell',
            'latestPendingBookingChangeRequestForBooking',
            "t('bookingChangeTypeCancel')",
            "t('bookingChangeTypeReschedule')",
            "message.direction === 'inbound'",
            "message.direction === 'outbound'",
            "message.role === 'assistant'",
            "t('clientBadge')",
            "t('aiBadge')",
            "t('yougoBadge')",
        ] as $needle) {
            $this->assertStringContainsString($needle, $source);
        }
    }

    private function createSalonWithUser(array $attributes = []): array
    {
        $user = User::factory()->create();
        $salon = $user->salon()->create(array_merge([
            'name' => 'YouGo Studio',
            'plan' => 'chat_whatsapp',
        ], $attributes));

        return [$salon, $user];
    }

    private function twilioPayload(array $overrides = []): array
    {
        return array_merge([
            'From' => 'whatsapp:+40711111111',
            'To' => 'whatsapp:+40700000000',
            'Body' => 'Buna',
            'MessageSid' => 'SM_INBOUND',
            'ProfileName' => 'Maria Client',
            'WaId' => '40711111111',
            'NumMedia' => '0',
        ], $overrides);
    }

    private function fakeGeminiText(string $text): void
    {
        Http::fake(['*' => Http::response([
            'candidates' => [[
                'content' => [
                    'parts' => [['text' => $text]],
                ],
            ]],
        ], 200)]);
    }

    private function fakeGeminiFunctionCall(string $name, array $args): void
    {
        Http::fake(['*' => Http::response([
            'candidates' => [[
                'content' => [
                    'parts' => [[
                        'functionCall' => [
                            'name' => $name,
                            'args' => $args,
                        ],
                    ]],
                ],
            ]],
        ], 200)]);
    }

    private function fakeTwilioService(bool $fail = false): void
    {
        $this->app->instance(TwilioWhatsAppService::class, new class($fail) extends TwilioWhatsAppService
        {
            public function __construct(private readonly bool $fail)
            {
            }

            public function sendMessage(string $from, string $to, string $body): array
            {
                if ($this->fail) {
                    throw new RuntimeException('Simulated Twilio failure.');
                }

                return [
                    'sid' => 'SM_TEST',
                    'status' => 'queued',
                    'from' => $this->normalizeAddress($from),
                    'to' => $this->normalizeAddress($to),
                ];
            }
        });
    }
}
