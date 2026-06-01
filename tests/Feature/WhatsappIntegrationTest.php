<?php

namespace Tests\Feature;

use App\Models\ConversationMessage;
use App\Models\Salon;
use App\Models\User;
use App\Models\WhatsappIntegration;
use App\Mail\BookingCancelledByCustomerMail;
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

    public function test_whatsapp_inbound_continues_open_conversation_without_booking(): void
    {
        config(['twilio.validate_signature' => false]);
        [$salon] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);
        $conversation = $salon->conversations()->create([
            'channel' => 'whatsapp',
            'provider' => 'twilio',
            'external_contact_id' => 'whatsapp:+40711111111',
            'external_sender' => 'whatsapp:+40700000000',
            'contact_phone' => 'whatsapp:+40711111111',
            'status' => 'open',
            'intent' => 'inquiry',
            'summary' => 'Open.',
            'last_message_at' => now(),
        ]);
        $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => 'active',
            'twilio_sender' => 'whatsapp:+40700000000',
            'ai_enabled' => false,
        ]);

        $this->post('/twilio/whatsapp/webhook', $this->twilioPayload([
            'MessageSid' => 'SM_CONTINUE_OPEN',
        ]))->assertOk();

        $this->assertSame(1, $salon->conversations()->count());
        $this->assertDatabaseHas('conversation_messages', [
            'conversation_id' => $conversation->id,
            'provider_message_id' => 'SM_CONTINUE_OPEN',
        ]);
    }

    public function test_whatsapp_inbound_continues_open_conversation_with_pending_booking(): void
    {
        config(['twilio.validate_signature' => false]);
        [$salon] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);
        $booking = $salon->bookings()->create([
            'client_name' => 'Maria Client',
            'client_phone' => '+40711111111',
            'date' => '2026-06-03',
            'time' => '10:00',
            'status' => 'pending',
            'source' => 'whatsapp',
        ]);
        $conversation = $salon->conversations()->create([
            'booking_id' => $booking->id,
            'channel' => 'whatsapp',
            'provider' => 'twilio',
            'external_contact_id' => 'whatsapp:+40711111111',
            'external_sender' => 'whatsapp:+40700000000',
            'contact_phone' => 'whatsapp:+40711111111',
            'status' => 'open',
            'intent' => 'booking',
            'summary' => 'Open.',
            'last_message_at' => now(),
        ]);
        $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => 'active',
            'twilio_sender' => 'whatsapp:+40700000000',
            'ai_enabled' => false,
        ]);

        $this->post('/twilio/whatsapp/webhook', $this->twilioPayload([
            'MessageSid' => 'SM_CONTINUE_PENDING',
        ]))->assertOk();

        $this->assertSame(1, $salon->conversations()->count());
        $this->assertDatabaseHas('conversation_messages', [
            'conversation_id' => $conversation->id,
            'provider_message_id' => 'SM_CONTINUE_PENDING',
        ]);
    }

    public function test_whatsapp_inbound_after_confirmed_booking_creates_new_dashboard_conversation(): void
    {
        config(['twilio.validate_signature' => false]);
        [$salon] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);
        $booking = $salon->bookings()->create([
            'client_name' => 'Maria Client',
            'client_phone' => '+40711111111',
            'date' => '2026-06-03',
            'time' => '10:00',
            'status' => 'confirmed',
            'source' => 'whatsapp',
        ]);
        $oldConversation = $salon->conversations()->create([
            'booking_id' => $booking->id,
            'channel' => 'whatsapp',
            'provider' => 'twilio',
            'external_contact_id' => 'whatsapp:+40711111111',
            'external_sender' => 'whatsapp:+40700000000',
            'contact_phone' => 'whatsapp:+40711111111',
            'status' => 'open',
            'intent' => 'booking',
            'summary' => 'Open.',
            'last_message_at' => now(),
        ]);
        $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => 'active',
            'twilio_sender' => 'whatsapp:+40700000000',
            'ai_enabled' => false,
        ]);

        $this->post('/twilio/whatsapp/webhook', $this->twilioPayload([
            'MessageSid' => 'SM_SPLIT_CONFIRMED',
        ]))->assertOk();

        $newConversation = $salon->conversations()->whereKeyNot($oldConversation->id)->firstOrFail();
        $this->assertSame('completed', $oldConversation->refresh()->status);
        $this->assertNull($newConversation->booking_id);
        $this->assertDatabaseHas('conversation_messages', [
            'conversation_id' => $newConversation->id,
            'provider_message_id' => 'SM_SPLIT_CONFIRMED',
        ]);
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

    public function test_whatsapp_outbound_guard_replaces_ro_website_chat_instruction_before_send(): void
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
        $this->fakeGeminiText('Te rog sa apasa pe + si sa incepi o conversatie noua.');
        $this->fakeTwilioService();

        $this->post('/twilio/whatsapp/webhook', $this->twilioPayload(['MessageSid' => 'SM_GUARD_RO']))->assertOk();

        $outbound = ConversationMessage::query()->where('direction', 'outbound')->latest()->firstOrFail();
        $this->assertSame('Putem continua aici pe WhatsApp. Pentru o programare noua te pot ajuta in continuare, iar pentru modificarea unei programari existente te rugam sa contactezi direct echipa.', $outbound->content);
        $this->assertTrue($outbound->metadata['outbound_guard_applied']);
        $this->assertSame('website_chat_instruction_removed', $outbound->metadata['outbound_guard_reason']);
    }

    public function test_whatsapp_outbound_guard_replaces_en_website_chat_instruction_before_send(): void
    {
        config([
            'twilio.validate_signature' => false,
            'services.gemini.key' => 'test-key',
        ]);
        [$salon] = $this->createSalonWithUser([
            'plan' => 'chat_whatsapp',
            'display_language' => 'en',
        ]);
        $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => 'active',
            'twilio_sender' => 'whatsapp:+40700000000',
            'ai_enabled' => true,
        ]);
        $this->fakeGeminiText('Please start a new conversation for a new booking.');
        $this->fakeTwilioService();

        $this->post('/twilio/whatsapp/webhook', $this->twilioPayload(['MessageSid' => 'SM_GUARD_EN']))->assertOk();

        $outbound = ConversationMessage::query()->where('direction', 'outbound')->latest()->firstOrFail();
        $this->assertSame('We can continue here on WhatsApp. I can help with a new booking, and for changes to an existing booking please contact the team directly.', $outbound->content);
        $this->assertTrue($outbound->metadata['outbound_guard_applied']);
        $this->assertSame('website_chat_instruction_removed', $outbound->metadata['outbound_guard_reason']);
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
            'content' => 'Imi pare rau, nu pot raspunde automat acum. Te rugam sa incerci din nou mai tarziu sau sa contactezi direct YouGo Studio.',
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

    public function test_whatsapp_booking_uses_from_number_when_ai_omits_client_phone(): void
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
        $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => 'active',
            'twilio_sender' => 'whatsapp:+40700000000',
            'ai_enabled' => true,
        ]);
        $this->fakeGeminiFunctionCall('bookBooking', [
            'client_name' => 'Maria Client',
            'location_id' => (string) $location->id,
            'service_id' => (string) $service->id,
            'date' => '2026-06-03',
            'time' => '10:00',
        ]);
        $this->fakeTwilioService();

        $this->post('/twilio/whatsapp/webhook', $this->twilioPayload([
            'From' => 'whatsapp:+447400606640',
            'WaId' => '447400606640',
        ]))->assertOk();

        $booking = $salon->bookings()->firstOrFail();

        $this->assertSame('+447400606640', $booking->client_phone);
        $this->assertSame('whatsapp', $booking->source);
    }

    public function test_whatsapp_confirmed_booking_edit_request_creates_new_conversation_and_uses_phone_handoff(): void
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
        $this->fakeGeminiText('Nu ar trebui apelat.');
        $this->fakeTwilioService();

        $this->post('/twilio/whatsapp/webhook', $this->twilioPayload([
            'Body' => 'Vreau sa schimb ora programarii la 12:00',
            'MessageSid' => 'SM_CHANGE',
        ]))->assertOk();

        $oldConversation = $conversation->refresh();
        $newConversation = $salon->conversations()->whereKeyNot($oldConversation->id)->firstOrFail();
        $booking->refresh();
        $outbound = ConversationMessage::query()->where('direction', 'outbound')->latest()->firstOrFail();

        $this->assertSame('completed', $oldConversation->status);
        $this->assertNull($newConversation->booking_id);
        $this->assertSame([], $newConversation->metadata['booking_change_requests'] ?? []);
        $this->assertSame('confirmed', $booking->status);
        $this->assertSame('10:00', $booking->time);
        $this->assertSame($newConversation->id, $outbound->conversation_id);
        $this->assertStringContainsString('contactezi direct YouGo Studio', $outbound->content);
        Mail::assertNothingSent();
        $this->assertStringNotContainsString('+', ConversationMessage::query()->where('direction', 'outbound')->latest()->firstOrFail()->content);
        $this->assertStringNotContainsString('conversatie noua', ConversationMessage::query()->where('direction', 'outbound')->latest()->firstOrFail()->content);
    }

    public function test_whatsapp_confirmed_booking_new_service_request_uses_new_conversation_phone_handoff(): void
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
        $this->fakeGeminiText('Nu ar trebui apelat.');
        $this->fakeTwilioService();

        $this->post('/twilio/whatsapp/webhook', $this->twilioPayload([
            'Body' => 'Vreau si un alt serviciu la 12:00',
            'MessageSid' => 'SM_NEW_SERVICE',
        ]))->assertOk();

        $oldConversation = $conversation->refresh();
        $newConversation = $salon->conversations()->whereKeyNot($oldConversation->id)->firstOrFail();
        $booking->refresh();
        $outbound = ConversationMessage::query()->where('direction', 'outbound')->latest()->firstOrFail();

        $this->assertSame('completed', $oldConversation->status);
        $this->assertNull($newConversation->booking_id);
        $this->assertSame([], $newConversation->metadata['booking_change_requests'] ?? []);
        $this->assertSame('confirmed', $booking->status);
        $this->assertSame('10:00', $booking->time);
        $this->assertStringContainsString('contactezi direct YouGo Studio', $outbound->content);
        $this->assertStringNotContainsString('+', $outbound->content);
        $this->assertStringNotContainsString('conversatie noua', $outbound->content);
    }

    public function test_whatsapp_confirmed_booking_reschedule_request_does_not_check_availability_and_uses_phone_handoff(): void
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
        $this->fakeGeminiText('Nu ar trebui apelat.');
        $this->fakeTwilioService();

        $this->post('/twilio/whatsapp/webhook', $this->twilioPayload([
            'Body' => 'Vreau sa schimb ora programarii pe 2026-06-03 la 12:00',
            'MessageSid' => 'SM_RESCHEDULE_UNAVAILABLE',
        ]))->assertOk();

        $oldConversation = $conversation->refresh();
        $newConversation = $salon->conversations()->whereKeyNot($oldConversation->id)->firstOrFail();
        $booking->refresh();
        $outbound = ConversationMessage::query()->where('direction', 'outbound')->latest()->firstOrFail();

        $this->assertSame('completed', $oldConversation->status);
        $this->assertNull($newConversation->booking_id);
        $this->assertSame([], $newConversation->metadata['booking_change_requests'] ?? []);
        $this->assertSame('confirmed', $booking->status);
        $this->assertSame('10:00', $booking->time);
        $this->assertSame($newConversation->id, $outbound->conversation_id);
        $this->assertStringContainsString('contactezi direct YouGo Studio', $outbound->content);
        Mail::assertNothingSent();
    }

    public function test_whatsapp_pending_booking_can_be_cancelled_by_customer_without_ai(): void
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
            'business_phone' => '+40700000001',
        ]);
        $location = $salon->locations()->create([
            'name' => 'Central',
            'address' => 'Main Street',
            'phone' => '+40700000002',
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
            'status' => 'pending',
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
            'status' => 'open',
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
        $this->fakeGeminiText('Nu ar trebui apelat.');
        $this->fakeTwilioService();

        $this->post('/twilio/whatsapp/webhook', $this->twilioPayload([
            'Body' => 'Vreau sa anulez programarea',
            'MessageSid' => 'SM_PENDING_CANCEL',
        ]))->assertOk();

        $conversation->refresh();
        $booking->refresh();
        $outbound = ConversationMessage::query()->where('direction', 'outbound')->latest()->firstOrFail();

        $this->assertSame('cancelled', $booking->status);
        $this->assertSame('completed', $conversation->status);
        $this->assertSame('Programarea ta a fost anulata. Multumim ca ne-ai anuntat.', $outbound->content);
        $this->assertSame('Vreau sa anulez programarea', $conversation->metadata['whatsapp_cancellations'][0]['cancellation_text']);
        $this->assertSame([], $conversation->metadata['booking_change_requests'] ?? []);
        Mail::assertSent(BookingCancelledByCustomerMail::class);
    }

    public function test_whatsapp_pending_booking_edit_request_uses_phone_handoff_without_metadata(): void
    {
        config([
            'twilio.validate_signature' => false,
            'services.gemini.key' => 'test-key',
        ]);
        Mail::fake();
        [$salon] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);
        $salon->update(['business_phone' => '+40700000001']);
        $location = $salon->locations()->create([
            'name' => 'Central',
            'address' => 'Main Street',
            'phone' => '+40700000002',
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
            'status' => 'pending',
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
            'status' => 'open',
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
        $this->fakeGeminiText('Nu ar trebui apelat.');
        $this->fakeTwilioService();

        $this->post('/twilio/whatsapp/webhook', $this->twilioPayload([
            'Body' => 'Vreau sa schimb ora programarii',
            'MessageSid' => 'SM_PENDING_EDIT',
        ]))->assertOk();

        $conversation->refresh();
        $booking->refresh();
        $outbound = ConversationMessage::query()->where('direction', 'outbound')->latest()->firstOrFail();

        $this->assertSame('pending', $booking->status);
        $this->assertSame('10:00', $booking->time);
        $this->assertSame('open', $conversation->status);
        $this->assertSame([], $conversation->metadata['booking_change_requests'] ?? []);
        $this->assertStringContainsString('+40700000001', $outbound->content);
        $this->assertStringContainsString('+40700000002', $outbound->content);
        Mail::assertNothingSent();
    }

    public function test_whatsapp_final_or_archived_linked_bookings_do_not_record_change_requests(): void
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
            'hours' => [
                'mon' => '10:00 - 18:00',
                'tue' => '10:00 - 18:00',
                'wed' => '10:00 - 18:00',
                'thu' => '10:00 - 18:00',
                'fri' => '10:00 - 18:00',
                'sat' => '10:00 - 18:00',
                'sun' => '10:00 - 18:00',
            ],
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
        $this->fakeGeminiText('Pot continua aici pe WhatsApp pentru o programare noua.');
        $this->fakeTwilioService();

        $cases = [
            ['status' => 'completed', 'date' => now()->addDays(2)->toDateString(), 'phone' => '+40711111111', 'sid' => 'SM_COMPLETED_CHANGE'],
            ['status' => 'cancelled', 'date' => now()->addDays(2)->toDateString(), 'phone' => '+40722222222', 'sid' => 'SM_CANCELLED_CHANGE'],
            ['status' => 'confirmed', 'date' => now()->subDay()->toDateString(), 'phone' => '+40733333333', 'sid' => 'SM_ARCHIVED_CHANGE'],
        ];

        foreach ($cases as $case) {
            $booking = $salon->bookings()->create([
                'location_id' => $location->id,
                'service_id' => $service->id,
                'client_name' => 'Maria Client',
                'client_phone' => $case['phone'],
                'date' => $case['date'],
                'time' => '10:00',
                'status' => $case['status'],
                'source' => 'whatsapp',
            ]);
            $conversation = $salon->conversations()->create([
                'booking_id' => $booking->id,
                'channel' => 'whatsapp',
                'provider' => 'twilio',
                'external_contact_id' => 'whatsapp:'.$case['phone'],
                'external_sender' => 'whatsapp:+40700000000',
                'contact_name' => 'Maria Client',
                'contact_phone' => 'whatsapp:'.$case['phone'],
                'status' => 'completed',
                'intent' => 'booking',
                'summary' => 'Booking created.',
                'last_message_at' => now(),
            ]);

            $this->post('/twilio/whatsapp/webhook', $this->twilioPayload([
                'From' => 'whatsapp:'.$case['phone'],
                'WaId' => ltrim($case['phone'], '+'),
                'Body' => 'Vreau sa schimb ora programarii la 12:00',
                'MessageSid' => $case['sid'],
            ]))->assertOk();

            $this->assertSame([], $conversation->refresh()->metadata['booking_change_requests'] ?? []);
            $this->assertSame($case['status'], $booking->refresh()->status);
            $this->assertSame('10:00', $booking->time);
        }

        Mail::assertNothingSent();
    }

    public function test_whatsapp_historical_completed_booking_does_not_block_new_booking(): void
    {
        config([
            'twilio.validate_signature' => false,
            'services.gemini.key' => 'test-key',
        ]);
        [$salon] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);
        $location = $salon->locations()->create([
            'name' => 'Central',
            'address' => 'Main Street',
            'hours' => [
                'mon' => '10:00 - 18:00',
                'tue' => '10:00 - 18:00',
                'wed' => '10:00 - 18:00',
                'thu' => '10:00 - 18:00',
                'fri' => '10:00 - 18:00',
                'sat' => '10:00 - 18:00',
                'sun' => '10:00 - 18:00',
            ],
        ]);
        $service = $salon->services()->create([
            'name' => 'Tuns',
            'price' => '100',
            'duration' => 30,
            'location_ids' => [$location->id],
        ]);
        $oldBooking = $salon->bookings()->create([
            'location_id' => $location->id,
            'service_id' => $service->id,
            'client_name' => 'Maria Client',
            'client_phone' => '+40711111111',
            'date' => now()->addDays(2)->toDateString(),
            'time' => '10:00',
            'status' => 'completed',
            'source' => 'whatsapp',
        ]);
        $conversation = $salon->conversations()->create([
            'booking_id' => $oldBooking->id,
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
            'date' => now()->addDays(3)->toDateString(),
            'time' => '12:00',
        ]);
        $this->fakeTwilioService();

        $this->post('/twilio/whatsapp/webhook', $this->twilioPayload([
            'Body' => 'Vreau inca o programare pentru copil la 12:00',
            'MessageSid' => 'SM_HISTORICAL_NEW_BOOKING',
        ]))->assertOk();

        $this->assertSame(2, $salon->bookings()->count());
        $this->assertSame('completed', $oldBooking->refresh()->status);
        $this->assertSame([], $conversation->refresh()->metadata['booking_change_requests'] ?? []);
        $newConversation = $salon->conversations()->whereKeyNot($conversation->id)->firstOrFail();
        $this->assertNotSame($oldBooking->id, $newConversation->booking_id);
        $this->assertDatabaseHas('bookings', [
            'salon_id' => $salon->id,
            'client_phone' => '+40711111111',
            'time' => '12:00',
            'status' => 'pending',
            'source' => 'whatsapp',
        ]);
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
            'content' => 'Momentan nu pot raspunde automat pe WhatsApp. Te rugam sa contactezi direct YouGo Studio.',
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

    public function test_user_can_resolve_own_whatsapp_booking_change_request_without_changing_booking_status(): void
    {
        [$salon, $user] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);
        $booking = $salon->bookings()->create([
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
            'status' => 'completed',
            'intent' => 'booking',
            'summary' => 'Booking created.',
            'last_message_at' => now(),
            'metadata' => [
                'booking_change_requests' => [[
                    'id' => 'req-1',
                    'type' => 'reschedule',
                    'requested_text' => 'Vreau la 12',
                    'source' => 'whatsapp',
                    'status' => 'pending',
                    'requested_at' => now()->toISOString(),
                    'previous_booking_status' => 'confirmed',
                ]],
            ],
        ]);

        $this->actingAs($user)
            ->patch("/dashboard/conversations/{$conversation->id}/booking-change-requests/req-1/resolve")
            ->assertRedirect();

        $request = $conversation->refresh()->metadata['booking_change_requests'][0];
        $this->assertSame('resolved', $request['status']);
        $this->assertNotEmpty($request['resolved_at']);
        $this->assertSame($user->id, $request['resolved_by_user_id']);
        $this->assertSame('confirmed', $booking->refresh()->status);
    }

    public function test_user_cannot_resolve_another_salons_booking_change_request(): void
    {
        [$salon] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);
        [, $otherUser] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);
        $conversation = $salon->conversations()->create([
            'channel' => 'whatsapp',
            'status' => 'completed',
            'intent' => 'booking',
            'summary' => 'Booking created.',
            'last_message_at' => now(),
            'metadata' => [
                'booking_change_requests' => [[
                    'id' => 'req-1',
                    'type' => 'reschedule',
                    'requested_text' => 'Vreau la 12',
                    'source' => 'whatsapp',
                    'status' => 'pending',
                    'requested_at' => now()->toISOString(),
                ]],
            ],
        ]);

        $this->actingAs($otherUser)
            ->patch("/dashboard/conversations/{$conversation->id}/booking-change-requests/req-1/resolve")
            ->assertForbidden();
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
            'needsActivation',
            'activationRequested',
            'activated',
            'activationError',
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
            'whatsappOutboundSendStatus',
            'bookingArchiveReadOnly',
            'bookingAllowsDashboardActions',
            "t('sendFailed')",
            "message.direction === 'inbound'",
            "message.direction === 'outbound'",
            "message.role === 'assistant'",
            "t('clientBadge')",
            "t('aiBadge')",
            "t('yougoBadge')",
        ] as $needle) {
            $this->assertStringContainsString($needle, $source);
        }

        $conversationsSource = substr(
            $source,
            strpos($source, 'function Conversations('),
            strpos($source, 'function conversationTitle') - strpos($source, 'function Conversations('),
        );

        foreach ([
            'selectedPendingRequests',
            'bookingChangeRequest',
            'pendingBookingChangeRequest',
            'markChangeResolved',
            '/booking-change-requests/${requestId}/resolve',
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $conversationsSource);
        }

        foreach ([
            'markChangeResolved',
            '/booking-change-requests/${requestId}/resolve',
            'pendingBookingChangeRequestsForBooking',
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $source);
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

