<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Salon extends Model
{
    use HasFactory;

    public const MODE_APPOINTMENT = 'appointment';
    public const MODE_RESERVATION = 'reservation';
    public const MODE_LEAD = 'lead';

    protected $fillable = [
        'user_id',
        'name',
        'plan',
        'plan_started_at',
        'trial_ends_at',
        'logo_path',
        'timezone',
        'industry',
        'mode',
        'business_type',
        'widget_key',
        'widget_enabled',
        'widget_allowed_domains',
        'widget_primary_color',
        'widget_position',
        'onboarding_completed',
        'onboarding_skipped',
        'onboarding_completed_at',
        'onboarding_skipped_at',
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
        'ai_assistant_name',
        'ai_tone',
        'ai_response_style',
        'ai_language_mode',
        'ai_custom_instructions',
        'ai_business_summary',
        'ai_industry_categories',
        'ai_main_focus',
        'ai_custom_context',
        'ai_booking_enabled',
        'ai_collect_phone',
        'ai_handoff_message',
        'ai_unknown_answer_policy',
    ];

    protected function casts(): array
    {
        return [
            'email_notifications' => 'boolean',
            'missed_call_alerts' => 'boolean',
            'booking_confirmations' => 'boolean',
            'widget_enabled' => 'boolean',
            'widget_allowed_domains' => 'array',
            'plan_started_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'onboarding_completed' => 'boolean',
            'onboarding_skipped' => 'boolean',
            'onboarding_completed_at' => 'datetime',
            'onboarding_skipped_at' => 'datetime',
            'service_categories' => 'array',
            'service_staff' => 'array',
            'ai_industry_categories' => 'array',
            'ai_custom_context' => 'array',
            'ai_booking_enabled' => 'boolean',
            'ai_collect_phone' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Salon $salon) {
            if (! $salon->widget_key) {
                $salon->widget_key = static::generateWidgetKey();
            }

            if (! $salon->widget_position) {
                $salon->widget_position = 'bottom-right';
            }

            if ($salon->widget_enabled === null) {
                $salon->widget_enabled = true;
            }

            if (! $salon->plan) {
                $salon->plan = 'free';
            }
        });
    }

    public static function generateWidgetKey(): string
    {
        do {
            $key = Str::random(40);
        } while (static::query()->where('widget_key', $key)->exists());

        return $key;
    }

    public function ensureWidgetKey(): string
    {
        if (! $this->widget_key) {
            $this->forceFill(['widget_key' => static::generateWidgetKey()])->save();
        }

        return $this->widget_key;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isAppointmentBased(): bool
    {
        return ($this->mode ?: self::MODE_APPOINTMENT) === self::MODE_APPOINTMENT;
    }

    public function isReservationBased(): bool
    {
        return $this->mode === self::MODE_RESERVATION;
    }

    public function isLeadBased(): bool
    {
        return $this->mode === self::MODE_LEAD;
    }

    /**
     * Salon currently represents the business account in this MVP.
     * It can be renamed or abstracted as Business when multi-industry modes are fully implemented.
     */
    public function displayName(): string
    {
        return $this->name;
    }

    public function businessLabel(): string
    {
        return $this->displayName();
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function staff(): HasMany
    {
        return $this->hasMany(Staff::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function usageEvents(): HasMany
    {
        return $this->hasMany(UsageEvent::class);
    }
}
