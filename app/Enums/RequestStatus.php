<?php

namespace App\Enums;

enum RequestStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case Contacted = 'contacted';
    case Resolved = 'resolved';
    case Closed = 'closed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
