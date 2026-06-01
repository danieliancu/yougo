<?php

namespace App\Services\WhatsApp;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Salon;
use App\Services\Usage\UsageTracker;
use App\Support\BookingStatus;

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
            ->with(['booking.salon'])
            ->where('channel', 'whatsapp')
            ->where('provider', 'twilio')
            ->where('external_contact_id', $from)
            ->where('external_sender', $to)
            ->latest('last_message_at')
            ->latest('id')
            ->first();

        if ($conversation && ! $this->canContinueConversation($conversation, $body)) {
            if ($conversation->status !== 'completed') {
                $conversation->update(['status' => 'completed']);
            }

            $conversation = null;
        }

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
                'summary' => $this->newConversationSummary($salon),
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

    private function canContinueConversation(Conversation $conversation, string $body): bool
    {
        if ($conversation->status !== 'open' && ! $this->isCourtesyMessage($body)) {
            return false;
        }

        if (! $conversation->booking) {
            return $conversation->status === 'open' || $this->isCourtesyMessage($body);
        }

        if (! BookingStatus::isClosedForWhatsappAi($conversation->booking)) {
            return true;
        }

        return $this->isCourtesyMessage($body) && ! $this->isOperationalMessage($body);
    }

    private function isCourtesyMessage(string $body): bool
    {
        $body = trim(mb_strtolower($body));

        if ($body === '') {
            return false;
        }

        return preg_match('/^(mersi|merci|multumesc|mul[Èțt]umesc|ms|ok|okay|bine|perfect|super|ne vedem|ne vedem atunci|o zi buna|o zi bun[Äăa]|thanks|thank you|thx|great|sounds good|see you|see you then)[.! ]*$/iu', $body) === 1;
    }

    private function isOperationalMessage(string $body): bool
    {
        return preg_match('/\b(vreau|programare|programez|serviciu|mai vreau|schimb|modific|mut|reprogram|anuleaz|anulare|la ce ora|la ce or[Äăa]|maine|m[âa]ine|luni|marti|mar[Èțt]i|miercuri|joi|vineri|sambata|s[âa]mb[Äăa]ta|duminica|ora\s*\d{1,2}|la\s*\d{1,2}|tuns|spalat|sp[Äăa]lat|epilat|booking|book me|another booking|another service|can you book|change|reschedule|move it|cancel|what time|tomorrow|monday|tuesday|wednesday|thursday|friday|saturday|sunday|at\s*\d{1,2})\b/iu', $body) === 1;
    }

    private function newConversationSummary(Salon $salon): string
    {
        return ($salon->display_language ?: config('app.locale', 'ro')) === 'en'
            ? 'New WhatsApp conversation.'
            : 'Conversatie WhatsApp noua.';
    }

    public function saveOutbound(Conversation $conversation, string $body, array $providerResult = [], array $metadata = []): ConversationMessage
    {
        $message = $conversation->messages()->create([
            'role' => 'assistant',
            'direction' => 'outbound',
            'provider' => 'twilio',
            'provider_message_id' => $providerResult['sid'] ?? null,
            'content' => $body,
            'metadata' => array_filter([
                ...$metadata,
                'provider_result' => $providerResult ?: null,
            ], fn ($value) => $value !== null),
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
