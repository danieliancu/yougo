<?php

namespace App\Services\Conversation;

use App\Models\Conversation;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The single place that creates a conversation's result (Booking or CustomerRequest) and
 * links it atomically (Task 4 §8) — used by both the appointment and request tool
 * handlers, so retries/concurrent tool-calls on the same conversation can never produce
 * two results or an orphaned row.
 *
 * `$createResult` runs inside the same transaction as the row lock and the result-column
 * write: if the conversation already has a result, `$createResult` is never invoked at
 * all (no wasted Booking/CustomerRequest row); if it throws, the whole transaction rolls
 * back and nothing is persisted.
 */
class ConversationResultService
{
    /**
     * @param  callable(): object  $createResult
     */
    public function createAndAttach(Conversation $conversation, string $resultType, callable $createResult): ?object
    {
        if (! array_key_exists($resultType, Relation::morphMap())) {
            throw new InvalidArgumentException("Unknown conversation result type [{$resultType}].");
        }

        return DB::transaction(function () use ($conversation, $resultType, $createResult) {
            $locked = Conversation::query()->whereKey($conversation->id)->lockForUpdate()->first();

            if (! $locked || $locked->result_type !== null || $locked->result_id !== null) {
                return null;
            }

            $result = $createResult();

            $attributes = [
                'result_type' => $resultType,
                'result_id' => $result->id,
            ];

            if ($resultType === Conversation::RESULT_TYPE_BOOKING) {
                $attributes['booking_id'] = $result->id;
            }

            $locked->forceFill($attributes)->save();
            $conversation->forceFill($attributes);

            return $result;
        });
    }
}
