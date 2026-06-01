<?php

namespace App\Services\WhatsApp;

use App\Models\Conversation;
use App\Services\Assistant\AssistantMessageLocalizer;
use Illuminate\Support\Facades\Log;

class WhatsAppOutboundGuard
{
    private const REASON_WEBSITE_CHAT_INSTRUCTION_REMOVED = 'website_chat_instruction_removed';

    private const FORBIDDEN_PATTERNS = [
        'ro_press_plus' => '/\bapas[Äƒa]\s+pe\s+\+/iu',
        'ro_plus_button' => '/\bbutonul\s+\+/iu',
        'ro_start_new_conversation' => '/\b[iÃ®]ncepe\s+o\s+conversa[tÈ›]ie\s+nou[Äƒa]\b/iu',
        'ro_new_conversation' => '/\bconversa[tÈ›]ie\s+nou[Äƒa]\b/iu',
        'ro_new_chat' => '/\bchat\s+nou\b/iu',
        'ro_separate_conversation' => '/\bconversa[tÈ›]ie\s+separat[Äƒa]\b/iu',
        'en_press_plus' => '/\bpress\s+(?:the\s+)?\+/iu',
        'en_plus_button' => '/\bplus\s+button\b/iu',
        'en_start_new_conversation' => '/\b(?:start|begin)\s+a\s+new\s+conversation\b/iu',
        'en_open_new_chat' => '/\bopen\s+a\s+new\s+chat\b/iu',
        'en_separate_conversation' => '/\bseparate\s+conversation\b/iu',
        'en_new_chat' => '/\bnew\s+chat\b/iu',
    ];

    public function __construct(private readonly AssistantMessageLocalizer $messageLocalizer)
    {
    }

    public function guard(Conversation $conversation, string $body, array $metadata = []): array
    {
        foreach (self::FORBIDDEN_PATTERNS as $patternId => $pattern) {
            if (preg_match($pattern, $body) !== 1) {
                continue;
            }

            Log::warning('WhatsApp outbound guard replaced website chat instruction.', [
                'salon_id' => $conversation->salon_id,
                'conversation_id' => $conversation->id,
                'matched_pattern' => $patternId,
            ]);

            return [
                'body' => $this->safeFallback($conversation),
                'metadata' => [
                    ...$metadata,
                    'outbound_guard_applied' => true,
                    'outbound_guard_reason' => self::REASON_WEBSITE_CHAT_INSTRUCTION_REMOVED,
                    'outbound_guard_pattern' => $patternId,
                ],
            ];
        }

        return [
            'body' => $body,
            'metadata' => $metadata,
        ];
    }

    private function safeFallback(Conversation $conversation): string
    {
        $salon = $conversation->salon;

        return $salon && $this->messageLocalizer->localeFor($salon) === 'en'
            ? 'We can continue here on WhatsApp. I can help with a new booking, and for changes to an existing booking please contact the team directly.'
            : 'Putem continua aici pe WhatsApp. Pentru o programare noua te pot ajuta in continuare, iar pentru modificarea unei programari existente te rugam sa contactezi direct echipa.';
    }
}
