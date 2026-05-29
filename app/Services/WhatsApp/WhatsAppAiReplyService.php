<?php

namespace App\Services\WhatsApp;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Salon;
use App\Models\WhatsappIntegration;
use App\Services\Assistant\AssistantChatService;
use App\Services\Assistant\AssistantMessageLocalizer;
use App\Services\Usage\UsageLimitService;
use App\Services\Usage\UsageTracker;
use Illuminate\Support\Facades\Log;
use Throwable;

class WhatsAppAiReplyService
{
    public function __construct(
        private readonly AssistantChatService $assistantChatService,
        private readonly AssistantMessageLocalizer $messageLocalizer,
        private readonly TwilioWhatsAppService $twilio,
        private readonly WhatsAppConversationService $conversations,
        private readonly UsageLimitService $usageLimitService,
        private readonly UsageTracker $usageTracker,
    ) {
    }

    public function handleInbound(Salon $salon, WhatsappIntegration $integration, ConversationMessage $inboundMessage, array $payload): void
    {
        $conversation = $inboundMessage->conversation;

        if (! $conversation) {
            Log::warning('WhatsApp AI reply skipped because inbound message has no conversation', [
                'salon_id' => $salon->id,
                'message_id' => $inboundMessage->id,
                'message_sid' => $payload['MessageSid'] ?? null,
            ]);

            return;
        }

        if (! $this->usageLimitService->canSendWhatsappMessage($salon)) {
            Log::info('WhatsApp AI reply skipped because monthly WhatsApp limit was reached', [
                'salon_id' => $salon->id,
                'conversation_id' => $conversation->id,
                'message_sid' => $payload['MessageSid'] ?? null,
            ]);

            $this->sendAndSave($conversation, $integration, $this->limitFallback($salon), [
                'channel' => 'whatsapp',
                'sent_via' => 'twilio',
                'ai_generated' => false,
                'status_reason' => 'usage_limit_reached',
            ]);

            return;
        }

        try {
            $result = $this->assistantChatService->replyForConversation($salon, $conversation, [
                'channel' => 'whatsapp',
                'booking_source' => 'whatsapp',
                'bill_booking_usage' => true,
                'save_assistant_message' => false,
                'known_contact' => [
                    'phone' => $this->cleanWhatsappAddress((string) ($payload['From'] ?? $conversation->external_contact_id ?? '')),
                    'name' => (string) ($conversation->contact_name ?? ''),
                ],
            ]);

            $reply = (string) ($result['body']['message'] ?? '');
            if (($result['status'] ?? 200) >= 500) {
                $reply = $this->aiFailureFallback($salon);
            }

            $this->sendAndSave($conversation, $integration, $this->trimReply($reply), [
                'channel' => 'whatsapp',
                'sent_via' => 'twilio',
                'ai_generated' => true,
                'assistant_status' => $result['status'] ?? 200,
            ]);

            $this->usageTracker->record($salon, 'whatsapp_ai_reply', source: 'whatsapp', metadata: [
                'conversation_id' => $conversation->id,
                'inbound_message_id' => $inboundMessage->id,
            ]);
        } catch (Throwable $exception) {
            Log::warning('WhatsApp AI reply failed', [
                'salon_id' => $salon->id,
                'conversation_id' => $conversation->id,
                'message_sid' => $payload['MessageSid'] ?? null,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $this->sendAndSave($conversation, $integration, $this->aiFailureFallback($salon), [
                'channel' => 'whatsapp',
                'sent_via' => 'twilio',
                'ai_generated' => false,
                'status_reason' => 'ai_failed',
            ]);
        }
    }

    public function sendUnsupportedTextMessage(Salon $salon, WhatsappIntegration $integration, Conversation $conversation): void
    {
        $this->sendAndSave($conversation, $integration, $this->unsupportedTextFallback($salon), [
            'channel' => 'whatsapp',
            'sent_via' => 'twilio',
            'ai_generated' => false,
            'status_reason' => 'unsupported_media',
        ]);
    }

    private function sendAndSave(Conversation $conversation, WhatsappIntegration $integration, string $body, array $metadata): void
    {
        $to = (string) ($conversation->external_contact_id ?? '');
        $from = (string) ($integration->twilio_sender ?? $conversation->external_sender ?? '');

        try {
            $providerResult = $this->twilio->sendMessage($from, $to, $body);
            $this->conversations->saveOutbound($conversation, $body, $providerResult, [
                ...$metadata,
                'status' => $providerResult['status'] ?? 'sent',
            ]);
        } catch (Throwable $exception) {
            Log::warning('WhatsApp AI outbound send failed after reply generation', [
                'salon_id' => $conversation->salon_id,
                'conversation_id' => $conversation->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $this->conversations->saveOutbound($conversation, $body, [], [
                ...$metadata,
                'status' => 'failed',
                'failure' => 'twilio_send_failed',
            ]);
        }
    }

    private function trimReply(string $reply): string
    {
        $reply = trim($reply);

        if (mb_strlen($reply) <= 1500) {
            return $reply;
        }

        return rtrim(mb_substr($reply, 0, 1470)).'...';
    }

    private function limitFallback(Salon $salon): string
    {
        return $this->messageLocalizer->localeFor($salon) === 'en'
            ? "I can't reply automatically on WhatsApp right now. Please contact the business directly."
            : 'Momentan nu pot raspunde automat pe WhatsApp. Te rugam sa contactezi direct businessul.';
    }

    private function aiFailureFallback(Salon $salon): string
    {
        return $this->messageLocalizer->localeFor($salon) === 'en'
            ? "Sorry, I can't reply automatically right now. Please try again later or contact the business directly."
            : 'Imi pare rau, nu pot raspunde automat acum. Te rugam sa incerci din nou mai tarziu sau sa contactezi direct businessul.';
    }

    private function unsupportedTextFallback(Salon $salon): string
    {
        return $this->messageLocalizer->localeFor($salon) === 'en'
            ? 'I can currently process text messages on WhatsApp only.'
            : 'Momentan pot procesa doar mesaje text pe WhatsApp.';
    }

    private function cleanWhatsappAddress(string $value): string
    {
        return preg_replace('/^whatsapp:/i', '', trim($value)) ?? '';
    }
}
