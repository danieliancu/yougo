<?php

namespace App\Services\Assistant;

use App\Models\Salon;
use App\Models\Conversation;
use App\Services\Booking\AvailabilityChecker;
use App\Services\Conversation\ConversationService;
use App\Services\Modes\Appointment\AppointmentToolHandler;
use App\Services\Notifications\BookingNotificationService;
use App\Services\Usage\UsageLimitService;
use App\Support\AssistantChannelBehavior;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AssistantChatService
{
    public function __construct(
        private readonly GeminiPayloadBuilder $payloadBuilder,
        private readonly AssistantResponseParser $responseParser,
        private readonly ConversationService $conversationService,
        private readonly AppointmentToolHandler $appointmentToolHandler,
        private readonly AvailabilityChecker $availabilityChecker,
        private readonly BookingNotificationService $bookingNotificationService,
        private readonly UsageLimitService $usageLimitService,
        private readonly AssistantMessageLocalizer $messageLocalizer,
    ) {
    }

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

        foreach ($parsed['function_calls'] as $functionCall) {
            if (! $this->appointmentToolHandler->canHandle($salon, $functionCall)) {
                continue;
            }

            if ($conversation->booking_id) {
                $booking = $conversation->booking;
                $channel = (string) ($options['channel'] ?? $conversation->channel);

                if (! AssistantChannelBehavior::allowsNewConversationInstruction($channel)) {
                    $availability = $this->appointmentToolHandler->isAvailabilityCall($functionCall)
                        ? $this->availabilityCheckForExistingBooking($salon, $conversation, $functionCall)
                        : [];

                    $this->conversationService->recordPendingBookingChangeRequest(
                        $conversation,
                        $this->latestUserMessageText($messages),
                        AssistantChannelBehavior::normalize($channel),
                        $this->pendingRequestType($functionCall),
                        $availability,
                    );

                    if (($availability['availability_status'] ?? null) === 'unavailable') {
                        $text = $this->messageLocalizer->bookingChangeRequestedTimeUnavailable(
                            $salon,
                            $availability['requested_date'] ?? null,
                            $availability['requested_time'] ?? null,
                        );
                        continue;
                    }

                    if (($availability['availability_status'] ?? null) === 'available') {
                        $text = $this->messageLocalizer->bookingChangeRequestedTimeAvailable(
                            $salon,
                            $availability['requested_date'] ?? null,
                            $availability['requested_time'] ?? null,
                        );
                        continue;
                    }
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
                $booking = $this->appointmentToolHandler->handle(
                    $salon,
                    $functionCall,
                    (string) ($options['booking_source'] ?? 'ai_assistant'),
                    (bool) ($options['bill_booking_usage'] ?? true),
                );
                $this->conversationService->attachBooking($conversation, $booking);
                $this->bookingNotificationService->sendAiBookingNotification($booking, $conversation);
                $text = $this->messageLocalizer->bookingConfirmation($salon, $booking);
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
            ],
            'status' => 200,
        ];
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
