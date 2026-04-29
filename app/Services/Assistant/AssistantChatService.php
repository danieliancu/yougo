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
    ) {
    }

    public function handle(Salon $salon, array $data, string $channel = 'chat'): array
    {
        $salon->load(['locations', 'services']);
        $conversationId = $data['conversation_id'] ?? null;
        $needsNewConversation = ! $this->conversationService->existsForSalon($salon, $conversationId ? (int) $conversationId : null);

        if ($needsNewConversation && ! $this->usageLimitService->canStartConversation($salon)) {
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

        if (! $this->usageLimitService->canSendAiMessage($salon)) {
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
                    'message' => 'Gemini nu este configurat inca. Adauga GEMINI_API_KEY in .env si reporneste serverul.',
                    'conversation_id' => $conversation->id,
                ],
                'status' => 503,
            ];
        }

        $response = $this->sendToGemini($salon, $data['messages'], $conversation);

        if (! $response->successful()) {
            $this->conversationService->updateTiming($conversation);

            return [
                'body' => [
                    'message' => 'Asistentul AI nu este disponibil momentan.',
                    'details' => $response->json('error.message'),
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
                $text = $this->newConversationRequiredMessage();
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
                $booking = $this->appointmentToolHandler->handle($salon, $functionCall);
                $this->conversationService->attachBooking($conversation, $booking);
                $this->bookingNotificationService->sendAiBookingNotification($booking, $conversation);
                $text = sprintf(
                    'Am inregistrat programarea pentru %s la ora %s. Te vom contacta pentru confirmare.',
                    $booking->date->locale('ro')->translatedFormat('j F'),
                    $booking->time
                );
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

    private function newConversationRequiredMessage(): string
    {
        return 'Pentru o programare noua, te rugam sa apesi pe + si sa incepi o conversatie noua.';
    }

    private function sendToGemini(Salon $salon, array $messages, ?Conversation $conversation = null)
    {
        $payload = $this->payloadBuilder->build($salon, $messages, $conversation);
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
