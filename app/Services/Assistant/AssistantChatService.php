<?php

namespace App\Services\Assistant;

use App\Models\Conversation;
use App\Models\Salon;
use App\Services\Conversation\ConversationResultService;
use App\Services\Conversation\ConversationService;
use App\Services\Modes\Appointment\AppointmentToolHandler;
use App\Services\Modes\Request\RequestToolHandler;
use App\Services\Notifications\BookingNotificationService;
use App\Services\Usage\UsageLimitService;
use App\Support\AssistantChannelBehavior;
use App\Support\BookingStatus;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AssistantChatService
{
    public function __construct(
        private readonly GeminiPayloadBuilder $payloadBuilder,
        private readonly AssistantResponseParser $responseParser,
        private readonly ConversationService $conversationService,
        private readonly ConversationResultService $conversationResultService,
        private readonly AppointmentToolHandler $appointmentToolHandler,
        private readonly RequestToolHandler $requestToolHandler,
        private readonly BookingNotificationService $bookingNotificationService,
        private readonly UsageLimitService $usageLimitService,
        private readonly AssistantMessageLocalizer $messageLocalizer,
    ) {}

    public function handle(Salon $salon, array $data, string $channel = 'chat'): array
    {
        $salon->load(['locations', 'services']);
        $conversationId = $data['conversation_id'] ?? null;
        $needsNewConversation = ! $this->conversationService->existsForSalon($salon, $conversationId ? (int) $conversationId : null);

        if ($channel === 'web_widget' && $needsNewConversation && ! $this->usageLimitService->canStartConversation($salon)) {
            return [
                'body' => [
                    'message' => $this->usageLimitService->limitMessage($salon),
                    'conversation_id' => null,
                ],
                'status' => 200,
            ];
        }

        $conversation = $this->conversationService->resolve($salon, $data['conversation_id'] ?? null, $channel);
        $this->conversationService->saveLatestUserMessage($conversation, $data['messages']);

        if ($this->handleDeterministicPostBookingMessage($salon, $conversation, $data['messages'], $channel)) {
            $conversation->refresh();

            return [
                'body' => [
                    'message' => $conversation->messages()->where('role', 'assistant')->latest()->value('content'),
                    'conversation_id' => $conversation->id,
                    'booking' => $conversation->booking?->load(['location', 'service']),
                ],
                'status' => 200,
            ];
        }

        if ($channel === 'web_widget' && ! $this->usageLimitService->canSendAiMessage($salon)) {
            $this->conversationService->updateTiming($conversation);

            return [
                'body' => [
                    'message' => $this->usageLimitService->limitMessage($salon),
                    'conversation_id' => $conversation->id,
                ],
                'status' => 200,
            ];
        }

        return $this->generateReply($salon, $conversation, $data['messages'], [
            'known_contact' => $data['known_contact'] ?? null,
            'channel' => $channel,
            'booking_source' => 'ai_assistant',
            'bill_booking_usage' => $channel === 'web_widget',
            'save_assistant_message' => true,
        ]);
    }

    public function replyForConversation(Salon $salon, Conversation $conversation, array $options = []): array
    {
        $salon->load(['locations', 'services']);
        $conversation->loadMissing(['messages', 'booking']);

        $messages = $conversation->messages
            ->sortBy('created_at')
            ->map(fn ($message) => [
                'role' => $message->role === 'assistant' ? 'assistant' : 'user',
                'content' => $message->content,
            ])
            ->values()
            ->all();

        return $this->generateReply($salon, $conversation, $messages, [
            'known_contact' => $options['known_contact'] ?? null,
            'channel' => $options['channel'] ?? $conversation->channel,
            'booking_source' => $options['booking_source'] ?? 'ai_assistant',
            'bill_booking_usage' => $options['bill_booking_usage'] ?? true,
            'save_assistant_message' => $options['save_assistant_message'] ?? false,
        ]);
    }

    private function generateReply(Salon $salon, Conversation $conversation, array $messages, array $options): array
    {
        if (! config('services.gemini.key')) {
            $this->conversationService->updateTiming($conversation);

            return [
                'body' => [
                    'message' => $this->messageLocalizer->geminiMissing($salon),
                    'conversation_id' => $conversation->id,
                ],
                'status' => 503,
            ];
        }

        $response = $this->sendToGemini($salon, $messages, $conversation, $options['known_contact'] ?? null);

        if (! $response->successful()) {
            $this->conversationService->updateTiming($conversation);

            return [
                'body' => [
                    'message' => $this->messageLocalizer->assistantUnavailable($salon),
                    'details' => app()->isLocal() ? $response->json('error.message') : null,
                ],
                'status' => 502,
            ];
        }

        $parsed = $this->responseParser->parse($response);
        $text = $parsed['text'];
        $booking = null;

        $customerRequest = null;

        foreach ($parsed['function_calls'] as $functionCall) {
            if ($this->requestToolHandler->canHandle($salon, $functionCall)) {
                if ($conversation->result_type !== null) {
                    $text = $this->messageLocalizer->existingResultRequiresNewConversation($salon);

                    continue;
                }

                try {
                    $customerRequest = $this->requestToolHandler->handle(
                        $salon,
                        $conversation,
                        $functionCall,
                        (string) ($options['channel'] ?? $conversation->channel),
                    );

                    if ($customerRequest) {
                        $this->conversationService->attachRequest($conversation, $customerRequest);
                        $text = $this->messageLocalizer->requestConfirmation($salon, $customerRequest);
                    }
                } catch (HttpException $e) {
                    $text = $e->getMessage();
                    $customerRequest = null;
                }

                continue;
            }

            if (! $this->appointmentToolHandler->canHandle($salon, $functionCall)) {
                continue;
            }

            $channel = (string) ($options['channel'] ?? $conversation->channel);
            $blocksExistingBookingTools = (bool) $conversation->booking_id;

            if ($blocksExistingBookingTools) {
                $booking = $conversation->booking;

                if (! AssistantChannelBehavior::allowsNewConversationInstruction($channel)) {
                    $text = $this->messageLocalizer->bookingChangePhoneHandoff($salon);

                    continue;
                }

                $text = $this->messageLocalizer->existingBookingRequiresNewConversation($salon, $channel);

                continue;
            }

            if ($this->appointmentToolHandler->isAvailabilityCall($functionCall)) {
                $text = $this->appointmentToolHandler->availabilityMessage($salon, $functionCall);

                continue;
            }

            if (! $this->appointmentToolHandler->isBookingCall($functionCall)) {
                continue;
            }

            try {
                $functionCall = $this->withKnownContactForBooking(
                    $functionCall,
                    $options['known_contact'] ?? null,
                    (string) ($options['channel'] ?? $conversation->channel),
                );

                $bookingSource = (string) ($options['booking_source'] ?? 'ai_assistant');
                $billUsage = (bool) ($options['bill_booking_usage'] ?? true);

                $booking = $this->conversationResultService->createAndAttach(
                    $conversation,
                    Conversation::RESULT_TYPE_BOOKING,
                    fn () => $this->appointmentToolHandler->handle($salon, $functionCall, $bookingSource, $billUsage),
                );

                if ($booking) {
                    $this->conversationService->attachBooking($conversation, $booking);
                    $this->bookingNotificationService->sendAiBookingNotification($booking, $conversation);
                    $text = $this->messageLocalizer->bookingConfirmation($salon, $booking);
                }
            } catch (HttpException $e) {
                $text = $e->getMessage();
                $booking = null;
            }
        }

        $message = $this->responseParser->finalText($text);

        if ($options['save_assistant_message'] ?? true) {
            $this->conversationService->saveAssistantMessageAndSummarize($conversation, $message);
        }

        $this->conversationService->updateTiming($conversation);

        return [
            'body' => [
                'message' => $message,
                'conversation_id' => $conversation->id,
                'booking' => $booking?->load(['location', 'service']),
                'customer_request' => $customerRequest,
            ],
            'status' => 200,
        ];
    }

    private function handleDeterministicPostBookingMessage(Salon $salon, Conversation $conversation, array $messages, string $channel): bool
    {
        $booking = $conversation->booking;
        if (! $booking) {
            return false;
        }

        $text = $this->latestUserMessageText($messages);
        if ($text === '') {
            return false;
        }

        if (BookingStatus::canCustomerCancelAutomatically($booking) && $this->looksLikeClearCancellationRequest($text)) {
            $this->storeCancellationMetadata($conversation, $text, $channel);
            $booking->update(['status' => 'cancelled']);
            $this->bookingNotificationService->sendCustomerCancelledBookingNotification($booking->refresh(), $text, $channel === 'web_widget' ? 'Website Chat' : $channel);
            $this->conversationService->saveAssistantMessageAndSummarize($conversation, $this->messageLocalizer->bookingCancelledByCustomer($salon));
            $conversation->update(['status' => 'completed']);

            return true;
        }

        if ($this->looksLikeClearCancellationRequest($text) || $this->looksLikeAmbiguousCancellationRequest($text)) {
            $this->conversationService->saveAssistantMessageAndSummarize($conversation, $this->messageLocalizer->bookingCancellationPhoneHandoff($salon, $this->handoffPhoneNumbers($salon, $booking)));
            $this->conversationService->updateTiming($conversation);

            return true;
        }

        if ($this->looksLikeBookingEditRequest($text)) {
            $this->conversationService->saveAssistantMessageAndSummarize($conversation, $this->messageLocalizer->bookingChangePhoneHandoff($salon, $this->handoffPhoneNumbers($salon, $booking)));
            $this->conversationService->updateTiming($conversation);

            return true;
        }

        return false;
    }

    private function storeCancellationMetadata(Conversation $conversation, string $text, string $channel): void
    {
        $metadata = $conversation->metadata ?? [];
        $cancellations = $metadata['customer_cancellations'] ?? [];
        $cancellations[] = [
            'cancelled_by' => 'ai_customer',
            'cancelled_source' => $channel === 'web_widget' ? 'website_chat' : $channel,
            'cancelled_at' => now()->toISOString(),
            'cancellation_text' => $text,
            'previous_booking_status' => $conversation->booking?->status,
        ];

        $conversation->update([
            'metadata' => [
                ...$metadata,
                'customer_cancellations' => $cancellations,
            ],
        ]);
    }

    private function looksLikeClearCancellationRequest(string $text): bool
    {
        return preg_match('/\b(anuleaz[Äƒăa]?|vreau\s+sa\s+anulez|vreau\s+s[Äƒăa]\s+anulez|nu\s+mai\s+vin|cancel(?:\s+(?:my\s+)?(?:booking|appointment))?|i\s+want\s+to\s+cancel|can[’\'`]?t\s+come\s+anymore)\b/iu', $text) === 1;
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

    private function latestUserMessageText(array $messages): string
    {
        return (string) (collect($messages)->where('role', 'user')->last()['content'] ?? '');
    }

    private function pendingRequestType(array $functionCall): string
    {
        return match ($functionCall['name'] ?? null) {
            'bookBooking' => 'new_booking_request',
            'checkAvailability' => 'reschedule',
            default => 'unknown',
        };
    }

    private function notifyPendingChangeRequestIfNeeded(Conversation $conversation, ?array $changeRequest, string $channel): void
    {
        if (! $changeRequest || $channel !== AssistantChannelBehavior::CHANNEL_WHATSAPP) {
            return;
        }

        if (($changeRequest['notified_at'] ?? null) || ($changeRequest['status'] ?? null) !== 'pending') {
            return;
        }

        if ($this->bookingNotificationService->sendBookingChangeRequestNotification($conversation, $changeRequest)) {
            $this->conversationService->markBookingChangeRequestNotified($conversation, $changeRequest['id']);
        }
    }

    private function withKnownContactForBooking(array $functionCall, ?array $knownContact, string $channel): array
    {
        if (! $this->appointmentToolHandler->isBookingCall($functionCall)) {
            return $functionCall;
        }

        if (AssistantChannelBehavior::normalize($channel) !== AssistantChannelBehavior::CHANNEL_WHATSAPP) {
            return $functionCall;
        }

        $phone = preg_replace('/^whatsapp:/i', '', trim((string) ($knownContact['phone'] ?? ''))) ?? '';
        if ($phone === '') {
            return $functionCall;
        }

        $args = $functionCall['args'] ?? [];
        if (trim((string) ($args['client_phone'] ?? '')) === '') {
            $args['client_phone'] = $phone;
        }

        return [
            ...$functionCall,
            'args' => $args,
        ];
    }

    private function availabilityCheckForExistingBooking(Salon $salon, Conversation $conversation, array $functionCall): array
    {
        $booking = $conversation->booking;
        $args = $functionCall['args'] ?? [];
        $date = trim((string) ($args['date'] ?? ''));
        $time = $this->normalizedTime((string) ($args['time'] ?? $args['preferred_time'] ?? ''));
        $locationId = (int) ($args['location_id'] ?? $booking?->location_id ?? 0);
        $serviceId = (int) ($args['service_id'] ?? $booking?->service_id ?? 0);
        $staffId = isset($args['staff_id']) ? (int) $args['staff_id'] : $booking?->staff_id;

        $metadata = [
            'availability_checked' => false,
            'availability_status' => 'missing_details',
            'requested_date' => $date ?: null,
            'requested_time' => $time,
            'availability_reason' => null,
        ];

        if (! $booking || $date === '' || ! $time || ! $locationId || ! $serviceId) {
            return $metadata;
        }

        try {
            $this->availabilityChecker->check($salon, $locationId, $serviceId, $date, $time, $staffId ?: null);

            return [
                ...$metadata,
                'availability_checked' => true,
                'availability_status' => 'available',
                'availability_reason' => null,
            ];
        } catch (HttpException $exception) {
            return [
                ...$metadata,
                'availability_checked' => true,
                'availability_status' => 'unavailable',
                'availability_reason' => $exception->getMessage(),
            ];
        }
    }

    private function normalizedTime(string $time): ?string
    {
        $time = trim(strtolower($time));

        if (preg_match('/^(\d{1,2})(?::|\.)(\d{2})$/', $time, $matches)) {
            $hour = (int) $matches[1];
            $minute = (int) $matches[2];
        } elseif (preg_match('/^\d{1,2}$/', $time)) {
            $hour = (int) $time;
            $minute = 0;
        } else {
            return null;
        }

        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    private function sendToGemini(Salon $salon, array $messages, ?Conversation $conversation = null, ?array $knownContact = null)
    {
        $payload = $this->payloadBuilder->build($salon, $messages, $conversation, $knownContact);
        $model = config('services.gemini.model', 'gemini-3-flash-preview');
        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

        return Http::withOptions([
            'proxy' => '',
            'verify' => config('services.gemini.ca_bundle'),
        ])
            ->timeout(30)
            ->post($endpoint.'?key='.config('services.gemini.key'), $payload);
    }
}
