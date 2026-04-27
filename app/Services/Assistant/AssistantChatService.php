<?php

namespace App\Services\Assistant;

use App\Models\Salon;
use App\Services\Booking\BookingCreator;
use App\Services\Conversation\ConversationService;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AssistantChatService
{
    public function __construct(
        private readonly GeminiPayloadBuilder $payloadBuilder,
        private readonly AssistantResponseParser $responseParser,
        private readonly ConversationService $conversationService,
        private readonly BookingCreator $bookingCreator,
    ) {
    }

    public function handle(Salon $salon, array $data): array
    {
        $salon->load(['locations', 'services']);
        $conversation = $this->conversationService->resolve($salon, $data['conversation_id'] ?? null);
        $this->conversationService->saveLatestUserMessage($conversation, $data['messages']);

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

        $response = $this->sendToGemini($salon, $data['messages']);

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
            if (($functionCall['name'] ?? null) !== 'bookBooking') {
                continue;
            }

            try {
                $booking = $this->bookingCreator->createFromAiFunctionCall($salon, $functionCall['args'] ?? []);
                $this->conversationService->attachBooking($conversation, $booking);
                $text = sprintf(
                    'Am inregistrat programarea pentru %s la ora %s. Te vom contacta pentru confirmare.',
                    $booking->date->format('Y-m-d'),
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

    private function sendToGemini(Salon $salon, array $messages)
    {
        $payload = $this->payloadBuilder->build($salon, $messages);
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
