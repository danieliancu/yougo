<?php

namespace App\Services\WhatsApp;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Salon;
use App\Services\Usage\UsageTracker;

class WhatsAppConversationService
{
    public function __construct(private readonly UsageTracker $usageTracker)
    {
    }

    public function hasProviderMessage(string $providerMessageId): bool
    {
        return ConversationMessage::query()
            ->where('provider', 'twilio')
            ->where('provider_message_id', $providerMessageId)
            ->exists();
    }

    public function saveInbound(Salon $salon, array $payload): ConversationMessage
    {
        $from = (string) ($payload['From'] ?? '');
        $to = (string) ($payload['To'] ?? '');
        $body = trim((string) ($payload['Body'] ?? ''));
        $profileName = trim((string) ($payload['ProfileName'] ?? ''));
        $messageSid = (string) ($payload['MessageSid'] ?? '');

        $conversation = $salon->conversations()
            ->where('channel', 'whatsapp')
            ->where('provider', 'twilio')
            ->where('external_contact_id', $from)
            ->where('external_sender', $to)
            ->first();

        if (! $conversation) {
            $conversation = $salon->conversations()->create([
                'channel' => 'whatsapp',
                'provider' => 'twilio',
                'external_contact_id' => $from,
                'external_sender' => $to,
                'contact_name' => $profileName ?: null,
                'contact_phone' => $from,
                'status' => 'open',
                'intent' => 'inquiry',
                'summary' => 'Conversatie WhatsApp primita prin Twilio.',
                'metadata' => [
                    'wa_id' => $payload['WaId'] ?? null,
                    'profile_name' => $profileName ?: null,
                ],
                'last_message_at' => now(),
            ]);

            $this->usageTracker->record($salon, 'whatsapp_conversation', source: 'whatsapp', metadata: [
                'conversation_id' => $conversation->id,
                'provider' => 'twilio',
            ]);
        }

        $message = $conversation->messages()->create([
            'role' => 'user',
            'direction' => 'inbound',
            'provider' => 'twilio',
            'provider_message_id' => $messageSid ?: null,
            'content' => $body,
            'metadata' => [
                'from' => $from,
                'to' => $to,
                'profile_name' => $profileName ?: null,
                'wa_id' => $payload['WaId'] ?? null,
                'num_media' => $payload['NumMedia'] ?? null,
            ],
        ]);

        $conversation->update([
            'contact_name' => $conversation->contact_name ?: ($profileName ?: null),
            'last_message_at' => now(),
        ]);

        $this->usageTracker->record($salon, 'whatsapp_message_inbound', source: 'whatsapp', metadata: [
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'provider' => 'twilio',
            'provider_message_id' => $messageSid ?: null,
        ]);

        return $message;
    }

    public function saveOutbound(Conversation $conversation, string $body, array $providerResult = []): ConversationMessage
    {
        $message = $conversation->messages()->create([
            'role' => 'assistant',
            'direction' => 'outbound',
            'provider' => 'twilio',
            'provider_message_id' => $providerResult['sid'] ?? null,
            'content' => $body,
            'metadata' => $providerResult ?: null,
        ]);

        $conversation->update(['last_message_at' => now()]);

        $this->usageTracker->record($conversation->salon, 'whatsapp_message_outbound', source: 'whatsapp', metadata: [
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'provider' => 'twilio',
            'provider_message_id' => $providerResult['sid'] ?? null,
        ]);

        return $message;
    }
}
