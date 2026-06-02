<?php

namespace App\Support;

class TwilioMessageStatus
{
    public const ACCEPTED = 'accepted';

    public const QUEUED = 'queued';

    public const SENDING = 'sending';

    public const SENT = 'sent';

    public const DELIVERED = 'delivered';

    public const READ = 'read';

    public const FAILED = 'failed';

    public const UNDELIVERED = 'undelivered';

    public const UNKNOWN = 'unknown';

    private const KNOWN_STATUSES = [
        self::ACCEPTED,
        self::QUEUED,
        self::SENDING,
        self::SENT,
        self::DELIVERED,
        self::READ,
        self::FAILED,
        self::UNDELIVERED,
    ];

    public static function normalize(?string $status): string
    {
        $status = strtolower(trim((string) $status));

        return in_array($status, self::KNOWN_STATUSES, true)
            ? $status
            : self::UNKNOWN;
    }

    public static function isFailure(string $status): bool
    {
        return in_array(self::normalize($status), [self::FAILED, self::UNDELIVERED], true);
    }

    public static function isDelivered(string $status): bool
    {
        return in_array(self::normalize($status), [self::DELIVERED, self::READ], true);
    }
}
