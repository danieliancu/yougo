<?php

namespace App\Models;

use App\Enums\OnboardingState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Salon extends Model
{
    use HasFactory;

    public const MODE_APPOINTMENT = 'appointment';

    public const MODE_RESERVATION = 'reservation';

    public const MODE_LEAD = 'lead';

    public const CAPABILITY_APPOINTMENT = 'appointment';

    public const CAPABILITY_REQUEST = 'request';

    public const CAPABILITY_RESERVATION = 'reservation';

    public const CAPABILITIES_SOURCE_DEFAULT = 'default';

    public const CAPABILITIES_SOURCE_CONFIRMED = 'confirmed';

    public const CAPABILITIES_SOURCE_CUSTOM = 'custom';

    protected $fillable = [
        'user_id',
        'name',
        'plan',
        'plan_started_at',
        'trial_ends_at',
        'stripe_customer_id',
        'stripe_subscription_id',
        'stripe_price_id',
        'subscription_status',
        'subscription_current_period_end',
        'logo_path',
        'timezone',
        'industry',
        'mode',
        'business_type',
        'recommended_primary_capability',
        'recommended_secondary_capabilities',
        'recommended_source_draft_id',
        'recommended_source_revision',
        'primary_capability',
        'enabled_capabilities',
        'capabilities_source',
        'widget_key',
        'widget_enabled',
        'widget_allowed_domains',
        'widget_primary_color',
        'widget_cta_text',
        'widget_position',
        'widget_setup_completed',
        'onboarding_completed',
        'onboarding_skipped',
        'onboarding_completed_at',
        'onboarding_skipped_at',
        'country',
        'currency',
        'phone_prefix',
        'website',
        'business_phone',
        'service_at_customer_location',
        'opening_hours',
        'notification_email',
        'onboarding_state',
        'email_notifications',
        'missed_call_alerts',
        'booking_confirmations',
        'booking_status_email_notifications',
        'display_language',
        'date_format',
        'service_categories',
        'service_staff',
        'ai_assistant_name',
        'ai_tone',
        'ai_response_style',
        'ai_language_mode',
        'ai_greeting_message',
        'ai_custom_instructions',
        'ai_business_summary',
        'ai_about_business',
        'ai_policies',
        'ai_faq',
        'ai_recommendations',
        'ai_avoid',
        'ai_industry_categories',
        'ai_main_focus',
        'ai_custom_context',
        'ai_booking_enabled',
        'ai_collect_phone',
        'ai_handoff_message',
        'ai_unknown_answer_policy',
        'ai_assistant_setup_completed',
    ];

    protected function casts(): array
    {
        return [
            'email_notifications' => 'boolean',
            'missed_call_alerts' => 'boolean',
            'booking_confirmations' => 'boolean',
            'booking_status_email_notifications' => 'boolean',
            'widget_enabled' => 'boolean',
            'widget_setup_completed' => 'boolean',
            'widget_allowed_domains' => 'array',
            'plan_started_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'subscription_current_period_end' => 'datetime',
            'onboarding_completed' => 'boolean',
            'onboarding_skipped' => 'boolean',
            'onboarding_completed_at' => 'datetime',
            'onboarding_skipped_at' => 'datetime',
            'onboarding_state' => OnboardingState::class,
            'service_at_customer_location' => 'boolean',
            'opening_hours' => 'array',
            'service_categories' => 'array',
            'service_staff' => 'array',
            'ai_industry_categories' => 'array',
            'ai_custom_context' => 'array',
            'recommended_secondary_capabilities' => 'array',
            'enabled_capabilities' => 'array',
            'ai_booking_enabled' => 'boolean',
            'ai_collect_phone' => 'boolean',
            'ai_assistant_setup_completed' => 'boolean',
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

            if (! $salon->onboarding_state) {
                $salon->onboarding_state = OnboardingState::SourcePending;
            }

            if (! $salon->plan) {
                $salon->plan = 'free';
            }

            if (! $salon->capabilities_source) {
                // Mirrors the add_business_capabilities_to_salons_table backfill: an
                // appointment (or unset) mode keeps today's already-active behavior; any
                // other explicit legacy mode (reservation/lead) starts with nothing
                // auto-enabled rather than silently activating a new capability.
                if (! $salon->mode || $salon->mode === self::MODE_APPOINTMENT) {
                    $salon->primary_capability = self::CAPABILITY_APPOINTMENT;
                    $salon->enabled_capabilities = [self::CAPABILITY_APPOINTMENT];
                    $salon->capabilities_source = self::CAPABILITIES_SOURCE_CONFIRMED;
                } else {
                    $salon->primary_capability = null;
                    $salon->enabled_capabilities = [];
                    $salon->capabilities_source = self::CAPABILITIES_SOURCE_DEFAULT;
                }
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
     * @return list<string>
     */
    public function enabledCapabilities(): array
    {
        // `[]` is a deliberate, meaningful value (e.g. an unconfirmed legacy account with
        // nothing active yet) and must NOT fall back to legacy `mode` matching — only a
        // genuinely unset (NULL) column does. `empty()` would treat both the same, which
        // silently reactivates appointment for accounts intentionally left capability-less.
        if ($this->enabled_capabilities !== null) {
            return $this->enabled_capabilities;
        }

        // Legacy fallback for rows that predate the capabilities columns entirely.
        return match ($this->mode) {
            self::MODE_RESERVATION, self::MODE_LEAD => [],
            default => [self::CAPABILITY_APPOINTMENT],
        };
    }

    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->enabledCapabilities(), true);
    }

    public function primaryCapability(): ?string
    {
        if ($this->primary_capability) {
            return $this->primary_capability;
        }

        $enabled = $this->enabledCapabilities();

        return $enabled[0] ?? null;
    }

    public function isCapabilitiesLocked(): bool
    {
        return $this->capabilities_source !== self::CAPABILITIES_SOURCE_DEFAULT;
    }

    /**
     * The single point that writes the active capability configuration. Never set
     * primary_capability/enabled_capabilities independently anywhere else — this is the
     * only place the "primary is always enabled" and "reservation can't be active"
     * invariants are enforced.
     *
     * @param  list<string>  $enabled
     */
    public function setCapabilities(string $primary, array $enabled, string $source): void
    {
        if (in_array(self::CAPABILITY_RESERVATION, $enabled, true)) {
            throw new \InvalidArgumentException('Reservation capability cannot be enabled yet.');
        }

        if (! in_array($primary, $enabled, true)) {
            throw new \InvalidArgumentException('Primary capability must be one of the enabled capabilities.');
        }

        $this->forceFill([
            'primary_capability' => $primary,
            'enabled_capabilities' => array_values($enabled),
            'capabilities_source' => $source,
        ])->save();
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

    public function faqs(): HasMany
    {
        return $this->hasMany(Faq::class);
    }

    public function policies(): HasMany
    {
        return $this->hasMany(Policy::class);
    }

    public function staff(): HasMany
    {
        return $this->hasMany(Staff::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function customerRequests(): HasMany
    {
        return $this->hasMany(CustomerRequest::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function usageEvents(): HasMany
    {
        return $this->hasMany(UsageEvent::class);
    }

    public function whatsappIntegration(): HasOne
    {
        return $this->hasOne(WhatsappIntegration::class);
    }

    public function onboardingDrafts(): HasMany
    {
        return $this->hasMany(OnboardingDraft::class);
    }

    public function activeOnboardingDraft(): ?OnboardingDraft
    {
        return $this->onboardingDrafts()->active()->first();
    }

    public function recommendedSourceDraft(): BelongsTo
    {
        return $this->belongsTo(OnboardingDraft::class, 'recommended_source_draft_id');
    }
}
