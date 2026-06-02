<?php

namespace Tests\Feature;

use App\Jobs\ProcessWhatsAppInboundMessage;
use App\Mail\BookingCancelledByCustomerMail;
use App\Mail\NewAiBookingMail;
use App\Mail\WhatsappSetupRequestMail;
use App\Models\ConversationMessage;
use App\Models\Salon;
use App\Models\User;
use App\Models\WhatsappIntegration;
use App\Services\WhatsApp\TwilioWhatsAppService;
use App\Services\WhatsApp\WhatsAppAiReplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

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

    public function test_request_activation_normalizes_00_prefix_to_international_number(): void
    {
        [$salon, $user] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);

        $this->actingAs($user)
            ->postJson('/dashboard/whatsapp/request-activation', [
                'requested_number' => '0040 711 111 111',
            ])
            ->assertOk()
            ->assertJsonPath('integration.requested_number', '+40711111111');

        $this->assertSame('+40711111111', $salon->refresh()->whatsappIntegration->requested_number);
    }

    public function test_request_activation_rejects_local_number_without_country_code(): void
    {
        [$salon, $user] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);

        $this->actingAs($user)
            ->postJson('/dashboard/whatsapp/request-activation', [
                'requested_number' => '0711 111 111',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('requested_number');

        $this->assertNull($salon->refresh()->whatsappIntegration);
    }

    public function test_setup_request_requires_auth(): void
    {
        $this->postJson('/dashboard/whatsapp/setup-request', $this->validWhatsappSetupRequest())
            ->assertUnauthorized();
    }

    public function test_setup_request_validates_required_fields(): void
    {
        [, $user] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);

        $this->actingAs($user)
            ->postJson('/dashboard/whatsapp/setup-request', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'contact_person',
                'contact_email',
                'contact_phone',
                'requested_whatsapp_number',
                'preferred_meeting_type',
                'preferred_availability',
            ]);
    }

    public function test_authenticated_user_can_submit_setup_request_email_without_changing_integration(): void
    {
        Mail::fake();
        config(['mail.whatsapp_setup_request_to' => 'dani.iancu@yahoo.com']);

        [$salon, $user] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);
        $integration = $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => 'requested',
            'requested_number' => '+40711111111',
            'requested_at' => now(),
        ]);

        $payload = $this->validWhatsappSetupRequest([
            'business_name' => 'YouGo Studio',
            'requested_whatsapp_number' => '+40711111111',
            'preferred_meeting_type' => 'video_call',
        ]);

        $this->actingAs($user)
            ->postJson('/dashboard/whatsapp/setup-request', $payload)
            ->assertOk()
            ->assertJsonPath('message', 'whatsapp_setup_request_sent');

        $integration->refresh();
        $this->assertSame('requested', $integration->status);
        $this->assertNull($integration->twilio_sender);
        $this->assertNull($integration->activated_at);

        Mail::assertSent(WhatsappSetupRequestMail::class, function (WhatsappSetupRequestMail $mail) use ($payload, $salon, $user) {
            return $mail->hasTo('dani.iancu@yahoo.com')
                && $mail->salon->is($salon)
                && $mail->user?->is($user)
                && $mail->form['requested_whatsapp_number'] === $payload['requested_whatsapp_number']
                && $mail->form['preferred_meeting_type'] === 'video_call'
                && ! array_key_exists('password', $mail->form)
                && ! array_key_exists('two_factor_code', $mail->form);
        });
    }

    public function test_setup_request_rejects_sensitive_login_fields(): void
    {
        Mail::fake();
        [, $user] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);

        $this->actingAs($user)
            ->postJson('/dashboard/whatsapp/setup-request', [
                ...$this->validWhatsappSetupRequest(),
                'password' => 'secret',
                'two_factor_code' => '123456',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password', 'two_factor_code']);

        Mail::assertNothingSent();
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

    public function test_whatsapp_courtesy_after_closed_booking_stays_in_old_transcript(): void
    {
        config(['twilio.validate_signature' => false]);
        [$salon] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);
        $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => 'active',
            'twilio_sender' => 'whatsapp:+40700000000',
            'ai_enabled' => false,
        ]);

        foreach ([
            ['status' => 'confirmed', 'sid' => 'SM_COURTESY_CONFIRMED', 'body' => 'multumesc'],
            ['status' => 'completed', 'sid' => 'SM_COURTESY_COMPLETED', 'body' => 'ok'],
            ['status' => 'cancelled', 'sid' => 'SM_COURTESY_CANCELLED', 'body' => 'see you'],
        ] as $case) {
            $booking = $salon->bookings()->create([
                'client_name' => 'Maria Client',
                'client_phone' => '+40711111111',
                'date' => '2026-06-03',
                'time' => '10:00',
                'status' => $case['status'],
                'source' => 'whatsapp',
            ]);
            $conversation = $salon->conversations()->create([
                'booking_id' => $booking->id,
                'channel' => 'whatsapp',
                'provider' => 'twilio',
                'external_contact_id' => 'whatsapp:+40711111111',
                'external_sender' => 'whatsapp:+40700000000',
                'contact_phone' => 'whatsapp:+40711111111',
                'status' => 'completed',
                'intent' => 'booking',
                'summary' => 'Booking created.',
                'last_message_at' => now(),
            ]);

            $this->post('/twilio/whatsapp/webhook', $this->twilioPayload([
                'Body' => $case['body'],
                'MessageSid' => $case['sid'],
            ]))->assertOk();

            $this->assertDatabaseHas('conversation_messages', [
                'conversation_id' => $conversation->id,
                'provider_message_id' => $case['sid'],
            ]);
        }

        $this->assertSame(3, $salon->conversations()->count());
    }

    public function test_whatsapp_operational_after_cancelled_booking_creates_new_dashboard_conversation(): void
    {
        config(['twilio.validate_signature' => false]);
        [$salon] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);
        $booking = $salon->bookings()->create([
            'client_name' => 'Maria Client',
            'client_phone' => '+40711111111',
            'date' => '2026-06-03',
            'time' => '10:00',
            'status' => 'cancelled',
            'source' => 'whatsapp',
        ]);
        $oldConversation = $salon->conversations()->create([
            'booking_id' => $booking->id,
            'channel' => 'whatsapp',
            'provider' => 'twilio',
            'external_contact_id' => 'whatsapp:+40711111111',
            'external_sender' => 'whatsapp:+40700000000',
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
            'ai_enabled' => false,
        ]);

        $this->post('/twilio/whatsapp/webhook', $this->twilioPayload([
            'Body' => 'vreau o programare maine la 10',
            'MessageSid' => 'SM_OPERATIONAL_AFTER_CANCELLED',
        ]))->assertOk();

        $newConversation = $salon->conversations()->whereKeyNot($oldConversation->id)->firstOrFail();
        $this->assertNull($newConversation->booking_id);
        $this->assertDatabaseHas('conversation_messages', [
            'conversation_id' => $newConversation->id,
            'provider_message_id' => 'SM_OPERATIONAL_AFTER_CANCELLED',
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

    public function test_webhook_dispatches_whatsapp_ai_job_without_sending_synchronously_when_queue_is_faked(): void
    {
        Queue::fake();
        config([
            'twilio.validate_signature' => false,
            'services.gemini.key' => 'test-key',
        ]);
        [$salon] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);
        $integration = $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => 'active',
            'twilio_sender' => 'whatsapp:+40700000000',
            'ai_enabled' => true,
        ]);
        $this->fakeGeminiText('Nu ar trebui procesat sincron');
        $this->fakeTwilioService();

        $this->post('/twilio/whatsapp/webhook', $this->twilioPayload())->assertOk();

        $inbound = ConversationMessage::query()->where('direction', 'inbound')->firstOrFail();
        Queue::assertPushed(ProcessWhatsAppInboundMessage::class, function (ProcessWhatsAppInboundMessage $job) use ($salon, $integration, $inbound) {
            return $job->inboundMessageId === $inbound->id
                && $job->salonId === $salon->id
                && $job->integrationId === $integration->id
                && $job->messageSid === 'SM_INBOUND'
                && $job->mode === ProcessWhatsAppInboundMessage::MODE_TEXT;
        });
        $this->assertDatabaseMissing('conversation_messages', [
            'direction' => 'outbound',
            'content' => 'Nu ar trebui procesat sincron',
        ]);
        $this->assertNotEmpty($inbound->refresh()->metadata['ai_reply_job_dispatched_at']);
    }

    public function test_webhook_dispatches_unsupported_media_job_without_sending_synchronously_when_queue_is_faked(): void
    {
        Queue::fake();
        config(['twilio.validate_signature' => false]);
        [$salon] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);
        $integration = $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => 'active',
            'twilio_sender' => 'whatsapp:+40700000000',
            'ai_enabled' => true,
        ]);
        $this->fakeTwilioService();

        $this->post('/twilio/whatsapp/webhook', $this->twilioPayload(['Body' => '', 'NumMedia' => '1']))->assertOk();

        $inbound = ConversationMessage::query()->where('direction', 'inbound')->firstOrFail();
        Queue::assertPushed(ProcessWhatsAppInboundMessage::class, function (ProcessWhatsAppInboundMessage $job) use ($salon, $integration, $inbound) {
            return $job->inboundMessageId === $inbound->id
                && $job->salonId === $salon->id
                && $job->integrationId === $integration->id
                && $job->mode === ProcessWhatsAppInboundMessage::MODE_UNSUPPORTED_MEDIA;
        });
        $this->assertSame(1, ConversationMessage::query()->count());
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

    public function test_whatsapp_ai_job_does_not_process_same_inbound_message_twice(): void
    {
        config([
            'twilio.validate_signature' => false,
            'services.gemini.key' => 'test-key',
        ]);
        [$salon] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);
        $integration = $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => 'active',
            'twilio_sender' => 'whatsapp:+40700000000',
            'ai_enabled' => true,
        ]);
        $inbound = $this->createQueuedInbound($salon);
        $this->fakeGeminiText('Un singur raspuns');
        $this->fakeTwilioService();

        $this->runWhatsAppJob($inbound, $salon, $integration);
        $this->runWhatsAppJob($inbound->refresh(), $salon, $integration);

        $this->assertSame(1, ConversationMessage::query()->where('direction', 'outbound')->count());
        Http::assertSentCount(1);
        $this->assertNotEmpty($inbound->refresh()->metadata['ai_reply_processed_at']);
    }

    public function test_whatsapp_ai_job_skips_when_integration_is_inactive_after_dispatch(): void
    {
        config([
            'twilio.validate_signature' => false,
            'services.gemini.key' => 'test-key',
        ]);
        [$salon] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);
        $integration = $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => 'active',
            'twilio_sender' => 'whatsapp:+40700000000',
            'ai_enabled' => true,
        ]);
        $inbound = $this->createQueuedInbound($salon, ['MessageSid' => 'SM_INACTIVE_JOB']);
        $integration->update(['status' => WhatsappIntegration::STATUS_DISABLED]);
        $this->fakeGeminiText('Nu ar trebui trimis');
        $this->fakeTwilioService();

        $this->runWhatsAppJob($inbound, $salon, $integration->refresh());

        $this->assertSame(1, ConversationMessage::query()->count());
        Http::assertSentCount(0);
    }

    public function test_whatsapp_ai_job_skips_when_ai_is_disabled_after_dispatch(): void
    {
        config([
            'twilio.validate_signature' => false,
            'services.gemini.key' => 'test-key',
        ]);
        [$salon] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);
        $integration = $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => 'active',
            'twilio_sender' => 'whatsapp:+40700000000',
            'ai_enabled' => true,
        ]);
        $inbound = $this->createQueuedInbound($salon, ['MessageSid' => 'SM_DISABLED_JOB']);
        $integration->update(['ai_enabled' => false]);
        $this->fakeGeminiText('Nu ar trebui trimis');
        $this->fakeTwilioService();

        $this->runWhatsAppJob($inbound, $salon, $integration->refresh());

        $this->assertSame(1, ConversationMessage::query()->count());
        Http::assertSentCount(0);
    }

    public function test_whatsapp_ai_job_skips_when_plan_lacks_entitlement_after_dispatch(): void
    {
        config([
            'twilio.validate_signature' => false,
            'services.gemini.key' => 'test-key',
        ]);
        [$salon] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);
        $integration = $salon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => 'active',
            'twilio_sender' => 'whatsapp:+40700000000',
            'ai_enabled' => true,
        ]);
        $inbound = $this->createQueuedInbound($salon, ['MessageSid' => 'SM_NO_PLAN_JOB']);
        $salon->update(['plan' => 'website_chat']);
        $this->fakeGeminiText('Nu ar trebui trimis');
        $this->fakeTwilioService();

        $this->runWhatsAppJob($inbound, $salon->refresh(), $integration);

        $this->assertSame(1, ConversationMessage::query()->count());
        Http::assertSentCount(0);
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

    public function test_whatsapp_status_callback_updates_outbound_delivery_metadata(): void
    {
        config(['twilio.validate_signature' => false]);
        $message = $this->createOutboundWhatsappMessage('SM_STATUS_DELIVERED');

        $this->post('/twilio/whatsapp/status', $this->twilioStatusPayload([
            'MessageSid' => 'SM_STATUS_DELIVERED',
            'MessageStatus' => 'delivered',
        ]))->assertOk();

        $metadata = $message->refresh()->metadata;
        $this->assertSame('delivered', $metadata['delivery_status']);
        $this->assertSame('delivered', $metadata['delivery']['status']);
        $this->assertSame('delivered', $metadata['delivery']['raw_status']);
        $this->assertCount(1, $metadata['delivery']['history']);
    }

    public function test_whatsapp_status_callback_uses_sms_status_fallback(): void
    {
        config(['twilio.validate_signature' => false]);
        $message = $this->createOutboundWhatsappMessage('SM_STATUS_SMS_FALLBACK');

        $this->post('/twilio/whatsapp/status', $this->twilioStatusPayload([
            'MessageSid' => 'SM_STATUS_SMS_FALLBACK',
            'MessageStatus' => null,
            'SmsStatus' => 'sent',
        ]))->assertOk();

        $this->assertSame('sent', $message->refresh()->metadata['delivery']['status']);
    }

    public function test_whatsapp_status_callback_stores_failed_error_details(): void
    {
        config(['twilio.validate_signature' => false]);
        $message = $this->createOutboundWhatsappMessage('SM_STATUS_FAILED');

        $this->post('/twilio/whatsapp/status', $this->twilioStatusPayload([
            'MessageSid' => 'SM_STATUS_FAILED',
            'MessageStatus' => 'failed',
            'ErrorCode' => '63038',
            'ErrorMessage' => 'Daily message limit exceeded',
        ]))->assertOk();

        $delivery = $message->refresh()->metadata['delivery'];
        $this->assertSame('failed', $delivery['status']);
        $this->assertSame('63038', $delivery['error_code']);
        $this->assertSame('Daily message limit exceeded', $delivery['error_message']);
    }

    public function test_whatsapp_status_callback_treats_undelivered_as_failure_status(): void
    {
        config(['twilio.validate_signature' => false]);
        $message = $this->createOutboundWhatsappMessage('SM_STATUS_UNDELIVERED');

        $this->post('/twilio/whatsapp/status', $this->twilioStatusPayload([
            'MessageSid' => 'SM_STATUS_UNDELIVERED',
            'MessageStatus' => 'undelivered',
        ]))->assertOk();

        $this->assertSame('undelivered', $message->refresh()->metadata['delivery']['status']);
    }

    public function test_whatsapp_status_callback_does_not_duplicate_same_history_event(): void
    {
        config(['twilio.validate_signature' => false]);
        $message = $this->createOutboundWhatsappMessage('SM_STATUS_DUPLICATE');
        $payload = $this->twilioStatusPayload([
            'MessageSid' => 'SM_STATUS_DUPLICATE',
            'MessageStatus' => 'delivered',
        ]);

        $this->post('/twilio/whatsapp/status', $payload)->assertOk();
        $this->post('/twilio/whatsapp/status', $payload)->assertOk();

        $this->assertCount(1, $message->refresh()->metadata['delivery']['history']);
    }

    public function test_whatsapp_status_callback_history_is_bounded(): void
    {
        config(['twilio.validate_signature' => false]);
        $message = $this->createOutboundWhatsappMessage('SM_STATUS_HISTORY');

        for ($i = 0; $i < 25; $i++) {
            $this->post('/twilio/whatsapp/status', $this->twilioStatusPayload([
                'MessageSid' => 'SM_STATUS_HISTORY',
                'MessageStatus' => 'failed',
                'ErrorCode' => (string) (60000 + $i),
            ]))->assertOk();
        }

        $history = $message->refresh()->metadata['delivery']['history'];
        $this->assertCount(20, $history);
        $this->assertSame('60005', $history[0]['error_code']);
        $this->assertSame('60024', $history[19]['error_code']);
    }

    public function test_whatsapp_status_callback_unknown_message_returns_ok_without_creating_message(): void
    {
        config(['twilio.validate_signature' => false]);

        $this->post('/twilio/whatsapp/status', $this->twilioStatusPayload([
            'MessageSid' => 'SM_UNKNOWN_STATUS',
            'MessageStatus' => 'delivered',
        ]))->assertOk();

        $this->assertDatabaseMissing('conversation_messages', [
            'provider_message_id' => 'SM_UNKNOWN_STATUS',
        ]);
    }

    public function test_whatsapp_status_callback_rejects_invalid_signature_when_validation_enabled(): void
    {
        config([
            'twilio.validate_signature' => true,
            'twilio.auth_token' => 'secret',
        ]);

        $this->withHeader('X-Twilio-Signature', 'invalid')
            ->post('/twilio/whatsapp/status', $this->twilioStatusPayload())
            ->assertForbidden();
    }

    public function test_whatsapp_status_callback_accepts_valid_signature(): void
    {
        config([
            'twilio.validate_signature' => true,
            'twilio.auth_token' => 'secret',
        ]);
        $message = $this->createOutboundWhatsappMessage('SM_STATUS_SIGNED');
        $payload = $this->twilioStatusPayload([
            'MessageSid' => 'SM_STATUS_SIGNED',
            'MessageStatus' => 'delivered',
        ]);
        $signature = (new RequestValidator('secret'))->computeSignature('http://localhost/twilio/whatsapp/status', $payload);

        $this->withHeader('X-Twilio-Signature', $signature)
            ->post('/twilio/whatsapp/status', $payload)
            ->assertOk();

        $this->assertSame('delivered', $message->refresh()->metadata['delivery']['status']);
    }

    public function test_twilio_whatsapp_service_includes_status_callback_when_configured(): void
    {
        config(['twilio.whatsapp_status_callback_url' => 'https://example.com/twilio/whatsapp/status']);

        $options = $this->twilioMessageOptions();

        $this->assertSame('https://example.com/twilio/whatsapp/status', $options['statusCallback']);
    }

    public function test_twilio_whatsapp_service_omits_status_callback_when_not_configured_locally(): void
    {
        config([
            'app.url' => 'http://localhost:8000',
            'twilio.whatsapp_status_callback_url' => null,
        ]);

        $options = $this->twilioMessageOptions();

        $this->assertArrayNotHasKey('statusCallback', $options);
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
        $this->assertSame('+40700000000', $integration->requested_number);
        $this->assertSame('manual', $integration->metadata['activation_source']);
        $this->assertSame('command', $integration->metadata['activated_by']);
        $this->assertNotNull($integration->activated_at);
    }

    public function test_manual_activation_command_accepts_whatsapp_sender_and_display_number(): void
    {
        [$salon] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);

        $this->artisan('yougo:whatsapp-activate', [
            'salon_id' => $salon->id,
            'twilio_sender' => 'whatsapp:+40700000001',
            '--display-number' => '+40 700 000 001',
        ])->assertSuccessful();

        $integration = $salon->refresh()->whatsappIntegration;
        $this->assertSame('whatsapp:+40700000001', $integration->twilio_sender);
        $this->assertSame('+40 700 000 001', $integration->display_number);
    }

    public function test_manual_activation_command_normalizes_00_sender_prefix(): void
    {
        [$salon] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);

        $this->artisan('yougo:whatsapp-activate', [
            'salon_id' => $salon->id,
            'twilio_sender' => '0040700000002',
        ])->assertSuccessful();

        $integration = $salon->refresh()->whatsappIntegration;
        $this->assertSame('whatsapp:+40700000002', $integration->twilio_sender);
        $this->assertSame('+40700000002', $integration->display_number);
    }

    public function test_manual_activation_command_prevents_duplicate_sender(): void
    {
        [$salon] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);
        [$otherSalon] = $this->createSalonWithUser(['plan' => 'chat_whatsapp']);

        $otherSalon->whatsappIntegration()->create([
            'provider' => 'twilio',
            'status' => 'active',
            'twilio_sender' => 'whatsapp:+40700000003',
        ]);

        $this->artisan('yougo:whatsapp-activate', [
            'salon_id' => $salon->id,
            'twilio_sender' => '+40700000003',
        ])->assertFailed();

        $this->assertNull($salon->refresh()->whatsappIntegration);
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
            '/dashboard/whatsapp/setup-request',
            '/dashboard/whatsapp/toggle',
            '/dashboard/whatsapp/test-message',
            'whatsappRequiresUpgrade',
            'needsActivation',
            'activationRequested',
            'activated',
            'activationError',
            'Planul tau nu include WhatsApp AI.',
            'Your plan does not include WhatsApp AI.',
            'Enter your WhatsApp Business number. The YouGo team will configure it and let you know when it is active.',
            'Enter your WhatsApp Business number',
            'To continue activation, complete the details below and choose how you prefer to complete the setup: video call or phone call.',
            'What happens next for WhatsApp AI activation?',
            'Setup call details',
            'Send setup details',
            'We received your setup details. The YouGo team will contact you to arrange the call.',
            'We will not ask for your Facebook password or authentication codes.',
            'whatsappSetupVideoCall',
            'whatsappSetupPhoneCall',
            'whatsappSetupAvailabilityDates',
            'whatsappSetupAvailabilityPeriods',
            'whatsappAvailabilityMorning',
            'whatsappAvailabilityAfternoon',
            'whatsappAvailabilityEvening',
            'type="date"',
            'availabilityPeriods',
            'preferred_meeting_type',
            'video_call',
            'phone_call',
        ] as $needle) {
            $this->assertStringContainsString($needle, $source.$translations);
        }

        foreach ([
            'whatsappTwilioManualActivationHelp',
            'label="Twilio"',
            'Twilio sender',
            'senderului Twilio',
            'configured in Twilio',
            'facebook_password',
            'meta_password',
            'two_factor_code',
            '2fa_code',
            '/dashboard/whatsapp/request-activation',
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $source);
        }

        $whatsappSettingsSource = substr(
            $source,
            strpos($source, 'function WhatsAppSettings('),
            strpos($source, 'function WhatsappActivationBadge') - strpos($source, 'function WhatsAppSettings('),
        );

        $this->assertStringNotContainsString('<select', $whatsappSettingsSource);
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
            'deliveryQueued',
            'deliveryDelivered',
            'deliveryRead',
            'deliveryUndelivered',
            'sendingUnconfirmed',
            'Error details',
            'Detalii eroare',
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
            "t('sendingUnconfirmed')",
            "t('deliveryDelivered')",
            "t('deliveryRead')",
            "t('deliveryUndelivered')",
            'metadata?.delivery_status',
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

    private function validWhatsappSetupRequest(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'YouGo Studio',
            'contact_person' => 'Maria Owner',
            'contact_email' => 'owner@example.com',
            'contact_phone' => '+40711111111',
            'requested_whatsapp_number' => '+40722222222',
            'whatsapp_display_name' => 'YouGo Studio',
            'website_or_social_link' => 'https://example.com',
            'has_meta_business_account' => 'yes',
            'number_currently_used_on_whatsapp_app' => 'not_sure',
            'can_receive_sms_or_call' => 'yes',
            'preferred_meeting_type' => 'video_call',
            'preferred_availability' => 'Tuesday after 14:00',
            'notes' => 'Prefer English setup call.',
        ], $overrides);
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

    private function twilioStatusPayload(array $overrides = []): array
    {
        return array_merge([
            'MessageSid' => 'SM_STATUS',
            'MessageStatus' => 'queued',
            'SmsStatus' => 'queued',
            'To' => 'whatsapp:+40711111111',
            'From' => 'whatsapp:+40700000000',
            'ErrorCode' => '',
            'ErrorMessage' => '',
            'ChannelPrefix' => 'whatsapp',
            'ApiVersion' => '2010-04-01',
            'AccountSid' => 'AC_TEST',
            'MessagingServiceSid' => '',
        ], $overrides);
    }

    private function createOutboundWhatsappMessage(string $messageSid = 'SM_STATUS'): ConversationMessage
    {
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

        return $conversation->messages()->create([
            'role' => 'assistant',
            'direction' => 'outbound',
            'provider' => 'twilio',
            'provider_message_id' => $messageSid,
            'content' => 'Raspuns WhatsApp',
            'metadata' => [
                'channel' => 'whatsapp',
                'sent_via' => 'twilio',
                'status' => 'queued',
                'provider_result' => [
                    'sid' => $messageSid,
                    'status' => 'queued',
                ],
            ],
        ]);
    }

    private function twilioMessageOptions(): array
    {
        return (new class extends TwilioWhatsAppService
        {
            public function options(): array
            {
                return $this->messageOptions('whatsapp:+40700000000', 'Test');
            }
        })->options();
    }

    private function createQueuedInbound(Salon $salon, array $payloadOverrides = []): ConversationMessage
    {
        $payload = $this->twilioPayload($payloadOverrides);
        $conversation = $salon->conversations()->create([
            'channel' => 'whatsapp',
            'provider' => 'twilio',
            'external_contact_id' => $payload['From'],
            'external_sender' => $payload['To'],
            'contact_name' => $payload['ProfileName'],
            'contact_phone' => $payload['From'],
            'status' => 'open',
            'intent' => 'inquiry',
            'summary' => 'Open.',
            'last_message_at' => now(),
        ]);

        return $conversation->messages()->create([
            'role' => 'user',
            'direction' => 'inbound',
            'provider' => 'twilio',
            'provider_message_id' => $payload['MessageSid'],
            'content' => $payload['Body'],
            'metadata' => [
                'from' => $payload['From'],
                'to' => $payload['To'],
                'ai_reply_job_dispatched_at' => now()->toISOString(),
                'ai_reply_mode' => ProcessWhatsAppInboundMessage::MODE_TEXT,
            ],
        ]);
    }

    private function runWhatsAppJob(
        ConversationMessage $inbound,
        Salon $salon,
        WhatsappIntegration $integration,
        string $mode = ProcessWhatsAppInboundMessage::MODE_TEXT,
    ): void {
        $payload = $this->twilioPayload([
            'From' => $inbound->conversation->external_contact_id,
            'To' => $inbound->conversation->external_sender,
            'Body' => $inbound->content,
            'MessageSid' => (string) $inbound->provider_message_id,
        ]);

        $job = new ProcessWhatsAppInboundMessage(
            inboundMessageId: $inbound->id,
            salonId: $salon->id,
            integrationId: $integration->id,
            messageSid: (string) $inbound->provider_message_id,
            payload: $payload,
            mode: $mode,
        );

        $job->handle($this->app->make(WhatsAppAiReplyService::class));
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
            public function __construct(private readonly bool $fail) {}

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
