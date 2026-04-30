<?php

namespace App\Services\Assistant;

use App\Models\Salon;
use App\Models\Conversation;
use App\Services\Conversation\ConversationService;
use App\Services\Modes\Appointment\AppointmentToolHandler;
use App\Services\Notifications\BookingNotificationService;
use App\Services\Usage\UsageLimitService;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AssistantChatService
{
    public function __construct(
        private readonly GeminiPayloadBuilder $payloadBuilder,
        private readonly AssistantResponseParser $responseParser,
        private readonly ConversationService $conversationService,
        private readonly AppointmentToolHandler $appointmentToolHandler,
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
        if ($data['voice_input_used'] ?? false) {
            $this->conversationService->markVoiceInputUsed($conversation);
        }
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

        $response = $this->sendToGemini($salon, $data['messages'], $conversation, $data['known_contact'] ?? null);

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
                $text = $this->messageLocalizer->existingBookingRequiresNewConversation($salon);
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
                $booking = $this->appointmentToolHandler->handle($salon, $functionCall, $channel === 'web_widget');
                $this->conversationService->attachBooking($conversation, $booking);
                $this->bookingNotificationService->sendAiBookingNotification($booking, $conversation);
                $text = $this->messageLocalizer->bookingConfirmation($salon, $booking);
            } catch (HttpException $e) {
                $text = $e->getMessage();
                $booking = null;
            }
        }

        $message = $this->responseParser->finalText($text);
        $this->conversationService->saveAssistantMessageAndSummarize($conversation, $message);
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
