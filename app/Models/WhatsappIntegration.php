<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappIntegration extends Model
{
    use HasFactory;

    public const STATUS_NOT_CONNECTED = 'not_connected';
    public const STATUS_REQUESTED = 'requested';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'salon_id',
        'provider',
        'requested_number',
        'twilio_sender',
        'display_number',
        'status',
        'ai_enabled',
        'last_verified_at',
        'activated_at',
        'requested_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'ai_enabled' => 'boolean',
            'last_verified_at' => 'datetime',
            'activated_at' => 'datetime',
            'requested_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function salon(): BelongsTo
    {
        return $this->belongsTo(Salon::class);
    }
}
