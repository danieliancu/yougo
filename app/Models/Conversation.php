<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Conversation extends Model
{
    use HasFactory;

    public const RESULT_TYPE_BOOKING = 'booking';

    public const RESULT_TYPE_CUSTOMER_REQUEST = 'customer_request';

    protected $fillable = [
        'salon_id',
        'booking_id',
        'result_type',
        'result_id',
        'customer_id',
        'channel',
        'provider',
        'external_contact_id',
        'external_sender',
        'visitor_number',
        'contact_name',
        'contact_phone',
        'contact_email',
        'status',
        'intent',
        'duration_seconds',
        'summary',
        'metadata',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'duration_seconds' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function salon(): BelongsTo
    {
        return $this->belongsTo(Salon::class);
    }

    /**
     * @deprecated Compatibility mirror only — see result(). Never used to decide whether
     * a conversation already has a result; that check always goes through result_type/id.
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * The single canonical "what did this conversation produce" relation (Task 4 §8) —
     * a Booking, a CustomerRequest, or nothing. Written exclusively via
     * ConversationResultService::createAndAttach().
     */
    public function result(): MorphTo
    {
        return $this->morphTo();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class);
    }
}
