<?php

namespace App\Services\Conversation;

use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Salon;
use App\Services\Usage\UsageTracker;

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

        $this->usageTracker->record($salon, 'conversation_started', source: $channel, metadata: [
            'conversation_id' => $conversation->id,
        ]);

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

        $this->usageTracker->record($conversation->salon, 'user_message', source: $conversation->channel, metadata: [
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
        ]);
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

        $this->usageTracker->record($conversation->salon, 'ai_message', source: $conversation->channel, metadata: [
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
        ]);

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
