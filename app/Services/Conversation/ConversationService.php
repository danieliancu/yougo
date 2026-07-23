<?php

namespace App\Services\Conversation;

use App\Models\Booking;
use App\Models\Conversation;
use App\Models\CustomerRequest;
use App\Models\Salon;
use App\Services\CRM\CustomerIdentityService;
use App\Services\Usage\UsageTracker;
use App\Support\BookingStatus;
use Illuminate\Support\Str;

class ConversationService
{
    public function __construct(
        private readonly UsageTracker $usageTracker,
        private readonly CustomerIdentityService $customers,
    ) {}

    public function existsForSalon(Salon $salon, ?int $conversationId): bool
    {
        return $conversationId
            ? $salon->conversations()->whereKey($conversationId)->exists()
            : false;
    }

    public function resolve(Salon $salon, ?int $conversationId, string $channel = 'chat'): Conversation
    {
        if ($conversationId) {
            $conversation = $salon->conversations()->whereKey($conversationId)->first();
            if ($conversation) {
                if ($conversation->intent === 'abandoned' && ! $conversation->booking_id) {
                    $conversation->update([
                        'intent' => 'inquiry',
                        'status' => 'open',
                    ]);
                }

                return $conversation;
            }
        }

        $used = $salon->conversations()->pluck('visitor_number')->filter()->sort()->values()->all();
        $next = 1;
        foreach ($used as $n) {
            if ($n > $next) {
                break;
            }
            $next = $n + 1;
        }

        $conversation = $salon->conversations()->create([
            'channel' => $channel,
            'status' => 'open',
            'intent' => 'inquiry',
            'visitor_number' => $next,
            'summary' => $channel === 'web_widget'
                ? 'Conversatie noua pornita din widgetul embed.'
                : 'Conversatie noua pornita din widgetul public.',
            'last_message_at' => now(),
        ]);

        if ($channel === 'web_widget') {
            $this->usageTracker->record($salon, 'conversation_started', source: $channel, metadata: [
                'conversation_id' => $conversation->id,
            ]);
        }

        return $conversation;
    }

    public function saveLatestUserMessage(Conversation $conversation, array $messages): void
    {
        $latestUserMessage = collect($messages)->where('role', 'user')->last();

        if (! $latestUserMessage) {
            return;
        }

        $message = $conversation->messages()->create([
            'role' => 'user',
            'content' => $latestUserMessage['content'],
        ]);

        if ($conversation->channel === 'web_widget') {
            $this->usageTracker->record($conversation->salon, 'user_message', source: $conversation->channel, metadata: [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
            ]);
        }
    }

    /**
     * Enriches the conversation with booking-derived metadata (contact info, status,
     * intent). The result linkage itself (booking_id/result_type/result_id) is already
     * written atomically by ConversationResultService::createAndAttach() before this runs.
     */
    public function attachBooking(Conversation $conversation, Booking $booking): void
    {
        $customer = $this->customers->identifyFromBooking($booking);

        $conversation->update([
            'customer_id' => $customer?->id,
            'contact_name' => $booking->client_name,
            'contact_phone' => $booking->client_phone,
            'status' => $conversation->channel === 'whatsapp' && ! BookingStatus::isClosedForWhatsappAi($booking)
                ? 'open'
                : 'completed',
            'intent' => 'booking',
        ]);

        $this->customers->identifyFromConversation($conversation->refresh());
    }

    /**
     * Mirrors attachBooking() for the `request` capability so a conversation that produced
     * a CustomerRequest is reflected the same way in intent/contact/status — otherwise it
     * stays `intent = inquiry` forever and the stale-inquiry sweep above can mislabel it
     * `abandoned` after an hour, even though it successfully produced a request. Customer
     * identification (CRM linking) is intentionally not wired here — CustomerRequest has no
     * `identifyFromRequest()` counterpart yet; only intent/contact/status are unified.
     */
    public function attachRequest(Conversation $conversation, CustomerRequest $customerRequest): void
    {
        $conversation->update([
            'contact_name' => $customerRequest->client_name,
            'contact_phone' => $customerRequest->client_phone,
            'status' => $conversation->channel === 'whatsapp' ? 'open' : 'completed',
            'intent' => 'request',
        ]);
    }

    public function closeWhatsappConversationsForBooking(Booking $booking): void
    {
        if (! BookingStatus::isClosedForWhatsappAi($booking)) {
            return;
        }

        $booking->conversations()
            ->where('channel', 'whatsapp')
            ->where('status', '!=', 'completed')
            ->update([
                'status' => 'completed',
                'last_message_at' => now(),
            ]);
    }

    public function saveAssistantMessageAndSummarize(Conversation $conversation, string $content): void
    {
        $message = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $content,
        ]);

        if ($conversation->channel === 'web_widget') {
            $this->usageTracker->record($conversation->salon, 'ai_message', source: $conversation->channel, metadata: [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
            ]);
        }

        $conversation->update([
            'summary' => $this->summarize($conversation),
        ]);
    }

    public function updateTiming(Conversation $conversation): void
    {
        $now = now();
        $startedAt = $conversation->created_at ?? $now;

        $conversation->update([
            'last_message_at' => $now,
            'duration_seconds' => max(0, (int) $startedAt->diffInSeconds($now, true)),
        ]);
    }

    public function recordPendingBookingChangeRequest(Conversation $conversation, string $requestedText, string $source, string $type = 'unknown', array $extra = []): ?array
    {
        $requestedText = trim($requestedText);
        if ($requestedText === '') {
            return null;
        }

        $metadata = $conversation->metadata ?? [];
        $requests = $metadata['booking_change_requests'] ?? [];
        $extra = array_filter($extra, fn ($value) => $value !== null);
        $existingIndex = collect($requests)->search(fn ($request) => ($request['requested_text'] ?? null) === $requestedText && ($request['status'] ?? null) === 'pending');

        if ($existingIndex !== false) {
            if ($extra === []) {
                return null;
            }

            $requests[$existingIndex] = [
                ...$requests[$existingIndex],
                ...$extra,
            ];

            $conversation->update([
                'metadata' => [
                    ...$metadata,
                    'booking_change_requests' => $requests,
                ],
            ]);

            return $requests[$existingIndex];
        }

        $request = [
            'id' => (string) Str::uuid(),
            'type' => $type,
            'requested_text' => Str::limit($requestedText, 2000, ''),
            'source' => $source,
            'status' => 'pending',
            'requested_at' => now()->toISOString(),
            'previous_booking_status' => $conversation->booking?->status,
            ...$extra,
        ];
        $requests[] = $request;

        $conversation->update([
            'metadata' => [
                ...$metadata,
                'booking_change_requests' => $requests,
            ],
        ]);

        return $request;
    }

    public function resolveBookingChangeRequest(Conversation $conversation, string $requestId, int $userId): ?array
    {
        $metadata = $conversation->metadata ?? [];
        $requests = $metadata['booking_change_requests'] ?? [];
        $resolvedRequest = null;

        $requests = collect($requests)
            ->map(function (array $request) use ($requestId, $userId, &$resolvedRequest) {
                if (($request['id'] ?? null) !== $requestId || ($request['status'] ?? null) !== 'pending') {
                    return $request;
                }

                $resolvedRequest = [
                    ...$request,
                    'status' => 'resolved',
                    'resolved_at' => now()->toISOString(),
                    'resolved_by_user_id' => $userId,
                ];

                return $resolvedRequest;
            })
            ->values()
            ->all();

        if (! $resolvedRequest) {
            return null;
        }

        $conversation->update([
            'metadata' => [
                ...$metadata,
                'booking_change_requests' => $requests,
            ],
        ]);

        return $resolvedRequest;
    }

    public function markBookingChangeRequestNotified(Conversation $conversation, string $requestId): void
    {
        $metadata = $conversation->metadata ?? [];
        $requests = collect($metadata['booking_change_requests'] ?? [])
            ->map(function (array $request) use ($requestId) {
                if (($request['id'] ?? null) !== $requestId) {
                    return $request;
                }

                return [
                    ...$request,
                    'notified_at' => now()->toISOString(),
                ];
            })
            ->values()
            ->all();

        $conversation->update([
            'metadata' => [
                ...$metadata,
                'booking_change_requests' => $requests,
            ],
        ]);
    }

    public function abandon(Salon $salon, int $conversationId): void
    {
        $conversation = $salon->conversations()->whereKey($conversationId)->first();

        if ($conversation && $conversation->intent !== 'booking') {
            $conversation->update(['intent' => 'abandoned', 'status' => 'completed']);
        }
    }

    private function summarize(Conversation $conversation): string
    {
        $conversation->loadMissing('salon');
        $messages = $conversation->messages()->latest()->limit(6)->get()->reverse();
        $userMessages = $messages->where('role', 'user')->pluck('content')->take(3)->implode(' ');
        $isEnglish = ($conversation->salon?->display_language ?: config('app.locale', 'ro')) === 'en';

        if ($conversation->booking_id) {
            if ($isEnglish) {
                return 'The client spoke with the assistant and created a booking that is waiting for confirmation.';
            }

            return 'Clientul a discutat cu asistentul si a creat o programare care asteapta confirmare.';
        }

        if ($userMessages) {
            if ($isEnglish) {
                return 'The client asked about '.$this->shorten($userMessages, 180);
            }

            return 'Clientul a intrebat despre '.$this->shorten($userMessages, 180);
        }

        if ($isEnglish) {
            return 'Conversation in progress with the virtual assistant.';
        }

        return 'Conversatie in desfasurare cu asistentul virtual.';
    }

    private function shorten(string $text, int $limit): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text));

        return strlen($text) > $limit ? substr($text, 0, $limit - 3).'...' : $text;
    }
}
