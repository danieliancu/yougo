<?php

namespace App\Services\WhatsApp;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Salon;
use App\Models\WhatsappIntegration;
use App\Services\Assistant\AssistantChatService;
use App\Services\Assistant\AssistantMessageLocalizer;
use App\Services\Conversation\ConversationService;
use App\Services\Notifications\BookingNotificationService;
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
        private readonly WhatsAppOutboundGuard $outboundGuard,
        private readonly ConversationService $conversationService,
        private readonly BookingNotificationService $bookingNotificationService,
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
            $this->recordPendingChangeRequestIfNeeded($conversation, $inboundMessage);

            $result = $this->assistantChatService->replyForConversation($salon, $conversation, [
                'channel' => 'whatsapp',
                'booking_source' => 'whatsapp',
                'bill_booking_usage' => true,
                'save_assistant_message' => false,
                'known_contact' => [
                    'phone' => $this->cleanWhatsappAddress((string) ($payload['From'] ?? $conversation->external_contact_id ?? '')),
                    'name' => (string) ($conversation->contact_name ?? ''),
                    'channel' => 'whatsapp',
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
        $guarded = $this->outboundGuard->guard($conversation, $body, $metadata);
        $body = $guarded['body'];
        $metadata = $guarded['metadata'];

        try {
            $providerResult = $this->twilio->sendMessage($from, $to, $body);
            $this->conversations->saveOutbound($conversation, $body, $providerResult, [
                ...$metadata,
                'status' => $providerResult['status'] ?? 'sent',
            ]);

            Log::info('WhatsApp AI outbound accepted by Twilio', [
                'salon_id' => $conversation->salon_id,
                'conversation_id' => $conversation->id,
                'provider_message_id' => $providerResult['sid'] ?? null,
                'provider_status' => $providerResult['status'] ?? null,
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

    private function recordPendingChangeRequestIfNeeded(Conversation $conversation, ConversationMessage $inboundMessage): void
    {
        if (! $conversation->booking_id || ! $this->looksLikeBookingChangeRequest($inboundMessage->content)) {
            return;
        }

        $changeRequest = $this->conversationService->recordPendingBookingChangeRequest(
            $conversation,
            $inboundMessage->content,
            'whatsapp',
            $this->classifyChangeRequest($inboundMessage->content),
        );

        if (! $changeRequest) {
            return;
        }

        if ($this->bookingNotificationService->sendBookingChangeRequestNotification($conversation, $changeRequest)) {
            $this->conversationService->markBookingChangeRequestNotified($conversation, $changeRequest['id']);
        }
    }

    private function looksLikeBookingChangeRequest(string $text): bool
    {
        if (preg_match('/\b(alt serviciu|serviciu nou|inca o programare|încă o programare|programare noua|programare nouă|pentru copil|pentru sotie|pentru so[țt]ie|another booking|new booking|another service|for my child|for my wife)\b/iu', $text) === 1) {
            return true;
        }
        return preg_match('/\b(schimb|modific|reprogram|mut|anul|cancel|alta ora|alt[aă] zi|alt serviciu|change|move|reschedul|cancel|another service|different service|different time)\b/iu', $text) === 1;
    }

    private function classifyChangeRequest(string $text): string
    {
        $normalized = mb_strtolower($text);

        if (preg_match('/\b(alt serviciu|serviciu nou|inca o programare|încă o programare|programare nou[ăa]|another booking|new booking|another service|for my child|for my wife|pentru copil|pentru so[țt]ie)\b/iu', $normalized) === 1) {
            return 'new_booking_request';
        }

        return match (true) {
            preg_match('/\b(anul|cancel)\b/iu', $normalized) === 1 => 'cancel',
            preg_match('/\b(reprogram|mut|ora|alt[aă] zi|move|reschedul|different time)\b/iu', $normalized) === 1 => 'reschedule',
            preg_match('/\b(serviciu|service)\b/iu', $normalized) === 1 => 'change_service',
            default => 'unknown',
        };
    }

    private function cleanWhatsappAddress(string $value): string
    {
        return preg_replace('/^whatsapp:/i', '', trim($value)) ?? '';
    }
}

