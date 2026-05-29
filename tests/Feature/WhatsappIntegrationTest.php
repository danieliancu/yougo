<?php

namespace Tests\Feature;

use App\Models\ConversationMessage;
use App\Models\Salon;
use App\Models\User;
use App\Models\WhatsappIntegration;
use App\Services\WhatsApp\TwilioWhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function fakeTwilioService(): void
    {
        $this->app->instance(TwilioWhatsAppService::class, new class extends TwilioWhatsAppService
        {
            public function sendMessage(string $from, string $to, string $body): array
            {
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
