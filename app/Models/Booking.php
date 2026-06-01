<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Booking extends Model
{
    use HasFactory;

    public const STATUSES = ['pending', 'confirmed', 'cancelled', 'completed'];
    public const AMENDABLE_STATUSES = ['pending', 'confirmed'];
    public const FINAL_STATUSES = ['cancelled', 'completed'];

    protected $fillable = [
        'salon_id',
        'location_id',
        'service_id',
        'staff_id',
        'client_name',
        'client_phone',
        'staff',
        'date',
        'time',
        'status',
        'source',
        'notification_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'staff' => 'array',
            'notification_sent_at' => 'datetime',
        ];
    }

    public function salon(): BelongsTo
    {
        return $this->belongsTo(Salon::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function staffMember(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function isAmendable(): bool
    {
        return in_array($this->status, self::AMENDABLE_STATUSES, true)
            && ! $this->isArchivedForDashboard();
    }

    public function isArchivedForDashboard(): bool
    {
        if (! $this->date) {
            return false;
        }

        $timezone = $this->salon?->timezone ?: config('app.timezone');

        return $this->date->toDateString() < Carbon::now($timezone)->toDateString();
    }
}
