<?php

namespace App\Enums;

/**
 * Intentionally small and generic (Task 4 §7: "evita o taxonomie excesiva") — quote/job/
 * callback/diagnostic/information cover the recommended examples without a bespoke type
 * per industry. Urgency is a priority on the request (RequestPriority), never a type.
 */
enum RequestType: string
{
    case General = 'general';
    case Quote = 'quote';
    case Job = 'job';
    case Callback = 'callback';
    case Diagnostic = 'diagnostic';
    case Information = 'information';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
