<?php

namespace App\Services\Conversation;

use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Salon;
use App\Services\Usage\UsageTracker;
use Illuminate\Support\Str;

class ConversationService
{
    public function __construct(private readonly UsageTracker $usageTracker)
    {
    }

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
            if ($n > $next) break;
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

    public function attachBooking(Conversation $conversation, Booking $booking): void
    {
        $conversation->update([
            'booking_id' => $booking->id,
            'contact_name' => $booking->client_name,
            'contact_phone' => $booking->client_phone,
            'status' => 'completed',
            'intent' => 'booking',
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
        $messages = $conversation->messages()->latest()->limit(6)->get()->reverse();
        $userMessages = $messages->where('role', 'user')->pluck('content')->take(3)->implode(' ');

        if ($conversation->booking_id) {
            return 'Clientul a discutat cu asistentul si a creat o programare care asteapta confirmare.';
        }

        if ($userMessages) {
            return 'Clientul a intrebat despre '.$this->shorten($userMessages, 180);
        }

        return 'Conversatie in desfasurare cu asistentul virtual.';
    }

    private function shorten(string $text, int $limit): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text));

        return strlen($text) > $limit ? substr($text, 0, $limit - 3).'...' : $text;
    }
}
