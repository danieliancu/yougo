<?php

namespace App\Services\Usage;

use App\Models\Salon;
use App\Models\UsageEvent;

class UsageTracker
{
    public function record(Salon $salon, string $eventType, int $quantity = 1, ?string $source = null, array $metadata = []): UsageEvent
    {
        return $salon->usageEvents()->create([
            'event_type' => $eventType,
            'source' => $source,
            'quantity' => max(1, $quantity),
            'metadata' => $metadata ?: null,
            'occurred_at' => now(),
        ]);
    }
}
