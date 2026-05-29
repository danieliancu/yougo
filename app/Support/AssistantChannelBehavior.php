<?php

namespace App\Support;

class AssistantChannelBehavior
{
    public const CHANNEL_CHAT = 'chat';
    public const CHANNEL_WHATSAPP = 'whatsapp';
    public const CHANNEL_PHONE = 'phone';

    public const POLICY_NEW_CONVERSATION_ALLOWED = 'new_conversation_allowed';
    public const POLICY_CONTINUE_SAME_THREAD = 'continue_same_thread';
    public const POLICY_CONTINUE_SAME_INTERACTION = 'continue_same_interaction';

    public const CHANGE_RECORD_PENDING_REQUEST = 'record_pending_request';

    public static function for(?string $channel): array
    {
        $normalized = self::normalize($channel);

        return match ($normalized) {
            self::CHANNEL_WHATSAPP => [
                'channel' => self::CHANNEL_WHATSAPP,
                'post_booking_policy' => self::POLICY_CONTINUE_SAME_THREAD,
                'allows_new_conversation_instruction' => false,
                'change_request_behavior' => self::CHANGE_RECORD_PENDING_REQUEST,
            ],
            self::CHANNEL_PHONE => [
                'channel' => self::CHANNEL_PHONE,
                'post_booking_policy' => self::POLICY_CONTINUE_SAME_INTERACTION,
                'allows_new_conversation_instruction' => false,
                'change_request_behavior' => self::CHANGE_RECORD_PENDING_REQUEST,
            ],
            default => [
                'channel' => self::CHANNEL_CHAT,
                'post_booking_policy' => self::POLICY_NEW_CONVERSATION_ALLOWED,
                'allows_new_conversation_instruction' => true,
                'change_request_behavior' => null,
            ],
        };
    }

    public static function normalize(?string $channel): string
    {
        return match ($channel) {
            'whatsapp' => self::CHANNEL_WHATSAPP,
            'phone', 'call', 'voice' => self::CHANNEL_PHONE,
            default => self::CHANNEL_CHAT,
        };
    }

    public static function allowsNewConversationInstruction(?string $channel): bool
    {
        return (bool) self::for($channel)['allows_new_conversation_instruction'];
    }
}
