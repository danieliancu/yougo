<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Salon extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'logo_path',
        'timezone',
        'industry',
        'country',
        'website',
        'business_phone',
        'notification_email',
        'email_notifications',
        'missed_call_alerts',
        'booking_confirmations',
        'display_language',
        'date_format',
        'service_categories',
        'service_staff',
    ];

    protected function casts(): array
    {
        return [
            'email_notifications' => 'boolean',
            'missed_call_alerts' => 'boolean',
            'booking_confirmations' => 'boolean',
            'service_categories' => 'array',
            'service_staff' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
