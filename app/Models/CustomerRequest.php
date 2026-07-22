<?php

namespace App\Models;

use App\Enums\RequestPriority;
use App\Enums\RequestStatus;
use App\Enums\RequestType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A qualified request created from a conversation (quote, job, callback, diagnostic,
 * information...) — deliberately not named `Request`, which would collide with
 * `Illuminate\Http\Request` already imported throughout the controller layer.
 *
 * Never has a `conversation_id` of its own: the conversation->result link is owned solely
 * by `conversations.result_type`/`result_id` (see decision #3 in the Task 4 plan) to avoid
 * two independently-writable sources of truth for the same relationship. Use
 * sourceConversation() to look it up.
 */
class CustomerRequest extends Model
{
    protected $fillable = [
        'salon_id',
        'customer_id',
        'location_id',
        'service_id',
        'assignee_staff_id',
        'channel',
        'type',
        'status',
        'priority',
        'title',
        'description',
        'structured_data',
        'preferred_date',
        'preferred_window',
        'client_name',
        'client_phone',
        'client_email',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'status' => RequestStatus::class,
            'priority' => RequestPriority::class,
            'type' => RequestType::class,
            'structured_data' => 'array',
        ];
    }

    public function salon(): BelongsTo
    {
        return $this->belongsTo(Salon::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'assignee_staff_id');
    }

    /**
     * The conversation that produced this request, if any — derived from
     * `conversations.result_type`/`result_id`, never from a column on this table.
     */
    public function sourceConversation(): HasOne
    {
        return $this->hasOne(Conversation::class, 'result_id')
            ->where('result_type', 'customer_request');
    }

    public function scopeForSalon(Builder $query, int $salonId): Builder
    {
        return $query->where('salon_id', $salonId);
    }
}
