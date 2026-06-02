<?php

namespace App\Services\WhatsApp;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Salon;
use App\Models\WhatsappIntegration;
use App\Services\Assistant\AssistantChatService;
use App\Services\Assistant\AssistantMessageLocalizer;
use App\Services\Notifications\BookingNotificationService;
use App\Services\Usage\UsageLimitService;
use App\Services\Usage\UsageTracker;
use App\Support\BookingStatus;
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
        private readonly BookingNotificationService $bookingNotificationService,
        private readonly UsageLimitService $usageLimitService,
        private readonly UsageTracker $usageTracker,
    ) {}

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
            ], $inboundMessage);

            return;
        }

        try {
            if ($this->handleDeterministicPostBookingMessage($salon, $integration, $conversation, $inboundMessage)) {
                return;
            }

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
                Log::warning('WhatsApp AI reply generation failed', [
                    'salon_id' => $salon->id,
                    'conversation_id' => $conversation->id,
                    'message_id' => $inboundMessage->id,
                    'message_sid' => $payload['MessageSid'] ?? null,
                    'assistant_status' => $result['status'] ?? null,
                ]);

                $reply = $this->aiFailureFallback($salon);
            }

            $this->sendAndSave($conversation, $integration, $this->trimReply($reply), [
                'channel' => 'whatsapp',
                'sent_via' => 'twilio',
                'ai_generated' => true,
                'assistant_status' => $result['status'] ?? 200,
            ], $inboundMessage);

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
            ], $inboundMessage);
        }
    }

    public function sendUnsupportedTextMessage(Salon $salon, WhatsappIntegration $integration, Conversation $conversation, ?ConversationMessage $inboundMessage = null): void
    {
        $this->sendAndSave($conversation, $integration, $this->unsupportedTextFallback($salon), [
            'channel' => 'whatsapp',
            'sent_via' => 'twilio',
            'ai_generated' => false,
            'status_reason' => 'unsupported_media',
        ], $inboundMessage);
    }

    private function sendAndSave(Conversation $conversation, WhatsappIntegration $integration, string $body, array $metadata, ?ConversationMessage $inboundMessage = null): void
    {
        if ($inboundMessage) {
            $metadata = [
                ...$metadata,
                'inbound_message_id' => $inboundMessage->id,
                'inbound_provider_message_id' => $inboundMessage->provider_message_id,
            ];
        }

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
                'code' => $exception->getCode() ?: null,
                'message' => $exception->getMessage(),
            ]);

            $this->conversations->saveOutbound($conversation, $body, [], [
                ...$metadata,
                'status' => 'failed',
                'failure' => 'twilio_send_failed',
                'twilio_error_code' => $exception->getCode() ?: null,
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
        $businessName = trim($salon->name) ?: ($this->messageLocalizer->localeFor($salon) === 'en' ? 'the business' : 'businessul');

        return $this->messageLocalizer->localeFor($salon) === 'en'
            ? "I can't reply automatically on WhatsApp right now. Please contact {$businessName} directly."
            : "Momentan nu pot raspunde automat pe WhatsApp. Te rugam sa contactezi direct {$businessName}.";
    }

    private function aiFailureFallback(Salon $salon): string
    {
        $businessName = trim($salon->name) ?: ($this->messageLocalizer->localeFor($salon) === 'en' ? 'the business' : 'businessul');

        return $this->messageLocalizer->localeFor($salon) === 'en'
            ? "Sorry, I can't reply automatically right now. Please try again later or contact {$businessName} directly."
            : "Imi pare rau, nu pot raspunde automat acum. Te rugam sa incerci din nou mai tarziu sau sa contactezi direct {$businessName}.";
    }

    private function unsupportedTextFallback(Salon $salon): string
    {
        return $this->messageLocalizer->localeFor($salon) === 'en'
            ? 'I can currently process text messages on WhatsApp only.'
            : 'Momentan pot procesa doar mesaje text pe WhatsApp.';
    }

    private function handleDeterministicPostBookingMessage(Salon $salon, WhatsappIntegration $integration, Conversation $conversation, ConversationMessage $inboundMessage): bool
    {
        $text = $inboundMessage->content;
        $booking = $conversation->booking;

        if ($booking) {
            $booking->loadMissing(['salon', 'location']);

            if (BookingStatus::isPendingCancellationAllowed($booking) && $this->looksLikeClearCancellationRequest($text)) {
                $metadata = $conversation->metadata ?? [];
                $cancellations = $metadata['whatsapp_cancellations'] ?? [];
                $cancellations[] = [
                    'source' => 'whatsapp',
                    'cancelled_by' => 'customer',
                    'cancelled_at' => now()->toISOString(),
                    'cancellation_text' => $text,
                    'previous_booking_status' => $booking->status,
                ];

                $conversation->update([
                    'metadata' => [
                        ...$metadata,
                        'whatsapp_cancellations' => $cancellations,
                    ],
                ]);

                $booking->update(['status' => 'cancelled']);
                $this->bookingNotificationService->sendCustomerCancelledBookingNotification($booking->refresh(), $text, 'WhatsApp');

                $this->sendAndSave($conversation, $integration, $this->messageLocalizer->bookingCancelledByCustomer($salon), [
                    'channel' => 'whatsapp',
                    'sent_via' => 'twilio',
                    'ai_generated' => false,
                    'status_reason' => 'pending_booking_cancelled_by_customer',
                ], $inboundMessage);

                $conversation->update(['status' => 'completed']);

                return true;
            }

            if ($this->looksLikeClearCancellationRequest($text) || $this->looksLikeAmbiguousCancellationRequest($text)) {
                $this->sendAndSave($conversation, $integration, $this->messageLocalizer->bookingCancellationPhoneHandoff($salon, $this->handoffPhoneNumbers($salon, $booking)), [
                    'channel' => 'whatsapp',
                    'sent_via' => 'twilio',
                    'ai_generated' => false,
                    'status_reason' => 'existing_booking_phone_handoff',
                ], $inboundMessage);

                return true;
            }

            if ($this->looksLikeBookingEditRequest($text)) {
                $this->sendAndSave($conversation, $integration, $this->messageLocalizer->bookingChangePhoneHandoff($salon, $this->handoffPhoneNumbers($salon, $booking)), [
                    'channel' => 'whatsapp',
                    'sent_via' => 'twilio',
                    'ai_generated' => false,
                    'status_reason' => 'existing_booking_phone_handoff',
                ], $inboundMessage);

                return true;
            }

            return false;
        }

        if ($this->looksLikeClearCancellationRequest($text) || $this->looksLikeAmbiguousCancellationRequest($text)) {
            $this->sendAndSave($conversation, $integration, $this->messageLocalizer->bookingCancellationPhoneHandoff($salon, $this->handoffPhoneNumbers($salon)), [
                'channel' => 'whatsapp',
                'sent_via' => 'twilio',
                'ai_generated' => false,
                'status_reason' => 'existing_booking_phone_handoff',
            ], $inboundMessage);

            return true;
        }

        if ($this->looksLikeBookingEditRequest($text)) {
            $this->sendAndSave($conversation, $integration, $this->messageLocalizer->bookingChangePhoneHandoff($salon, $this->handoffPhoneNumbers($salon)), [
                'channel' => 'whatsapp',
                'sent_via' => 'twilio',
                'ai_generated' => false,
                'status_reason' => 'existing_booking_phone_handoff',
            ], $inboundMessage);

            return true;
        }

        return false;
    }

    private function looksLikeClearCancellationRequest(string $text): bool
    {
        return preg_match('/\b(anuleaz[Äƒăa]?|vreau\s+sa\s+anulez|vreau\s+s[Äƒăa]\s+anulez|nu\s+mai\s+vin|cancel(?:\s+my\s+booking)?|i\s+want\s+to\s+cancel|can[’\'`]?t\s+come\s+anymore)\b/iu', $text) === 1;
    }

    private function looksLikeAmbiguousCancellationRequest(string $text): bool
    {
        return preg_match('/\b(anulare|anulat|cancel|cancellation)\b/iu', $text) === 1;
    }

    private function looksLikeBookingEditRequest(string $text): bool
    {
        return preg_match('/\b(schimb[Äƒăa]?|modific[Äƒăa]?|reprogram|mut[Äƒăa]?|alta\s+ora|alt[Äƒăa]\s+ora|alta\s+zi|alt[Äƒăa]\s+zi|alt\s+serviciu|serviciu\s+diferit|change|edit|reschedul|move|different\s+time|different\s+day|different\s+service|change\s+location|change\s+service|amend)\b/iu', $text) === 1;
    }

    private function handoffPhoneNumbers(Salon $salon, mixed $booking = null): array
    {
        $phones = collect([$salon->business_phone]);

        if ($booking?->location?->phone) {
            $phones->push($booking->location->phone);
        }

        if (! $booking) {
            $salon->loadMissing('locations');
            foreach ($salon->locations as $location) {
                if ($location->phone) {
                    $phones->push($location->phone);
                }
            }
        }

        return $phones
            ->map(fn ($phone) => trim((string) $phone))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function cleanWhatsappAddress(string $value): string
    {
        return preg_replace('/^whatsapp:/i', '', trim($value)) ?? '';
    }
}
