<?php

namespace App\Services\PlatformAdmin;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Salon;
use App\Models\UsageEvent;
use App\Models\WhatsappIntegration;
use App\Services\Usage\UsageLimitService;
use App\Support\YouGoServices;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PlatformAdminService
{
    public function __construct(private readonly UsageLimitService $usageLimits) {}

    public function overview(): array
    {
        $monthStart = now()->startOfMonth();

        return [
            'totals' => [
                'businesses' => $this->businessesQuery()->count(),
                'active' => $this->businessesQuery()->whereIn('subscription_status', ['active', 'trialing'])->count(),
                'free' => $this->businessesQuery()->where('plan', 'free')->count(),
                'paid' => $this->businessesQuery()->where('plan', '!=', 'free')->count(),
                'whatsapp_requested' => $this->whatsappIntegrationsQuery()->where('status', WhatsappIntegration::STATUS_REQUESTED)->count(),
                'whatsapp_active' => $this->whatsappIntegrationsQuery()->where('status', WhatsappIntegration::STATUS_ACTIVE)->count(),
                'whatsapp_failed' => $this->whatsappIntegrationsQuery()->where('status', WhatsappIntegration::STATUS_FAILED)->count(),
                'whatsapp_disabled' => $this->whatsappIntegrationsQuery()->where('status', WhatsappIntegration::STATUS_DISABLED)->count(),
                'whatsapp_messages' => UsageEvent::query()
                    ->whereHas('salon.user')
                    ->whereIn('event_type', ['whatsapp_message_inbound', 'whatsapp_message_outbound'])
                    ->where('occurred_at', '>=', $monthStart)
                    ->sum('quantity'),
                'ai_bookings' => UsageEvent::query()
                    ->whereHas('salon.user')
                    ->where('event_type', 'booking_created')
                    ->where('occurred_at', '>=', $monthStart)
                    ->sum('quantity'),
                'website_chat_conversations' => Conversation::query()
                    ->whereHas('salon.user')
                    ->whereIn('channel', ['chat', 'web_widget'])
                    ->where('created_at', '>=', $monthStart)
                    ->count(),
                'phone_ai' => 'planned',
            ],
            'issue_summary' => $this->issueSummary(),
            'recent_businesses' => $this->businessesQuery()
                ->with(['user', 'whatsappIntegration'])
                ->latest()
                ->limit(8)
                ->get()
                ->map(fn (Salon $salon) => [
                    ...$this->businessRow($salon),
                    'usage' => $this->usageLimits->usageSummary($salon),
                ])
                ->values(),
        ];
    }

    public function businesses(array $filters = []): array
    {
        $query = $this->businessesQuery()
            ->with(['user', 'whatsappIntegration'])
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhereHas('user', fn (Builder $userQuery) => $userQuery
                            ->where('email', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%"));
                });
            })
            ->when($filters['plan'] ?? null, fn (Builder $query, string $plan) => $query->where('plan', $plan))
            ->when($filters['subscription_status'] ?? null, fn (Builder $query, string $status) => $query->where('subscription_status', $status))
            ->when($filters['whatsapp_status'] ?? null, fn (Builder $query, string $status) => $query->whereHas('whatsappIntegration', fn (Builder $integration) => $integration->where('status', $status)))
            ->latest();

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate(25)->withQueryString();

        return [
            'filters' => [
                'search' => $filters['search'] ?? '',
                'plan' => $filters['plan'] ?? '',
                'subscription_status' => $filters['subscription_status'] ?? '',
                'whatsapp_status' => $filters['whatsapp_status'] ?? '',
            ],
            'items' => $paginator->getCollection()
                ->map(fn (Salon $salon) => [
                    ...$this->businessRow($salon),
                    'usage' => $this->usageLimits->usageSummary($salon),
                ])
                ->values(),
            'pagination' => $this->pagination($paginator),
            'plans' => collect(YouGoServices::plans())->pluck('name', 'key'),
        ];
    }

    public function businessDetail(Salon $salon): array
    {
        abort_unless($salon->user()->exists(), 404);

        $salon->load(['user', 'whatsappIntegration', 'locations', 'services', 'staff']);

        return [
            'business' => $this->businessRow($salon),
            'profile' => [
                'id' => $salon->id,
                'name' => $salon->name,
                'business_type' => $salon->business_type,
                'website' => $salon->website,
                'phone' => $salon->business_phone,
                'notification_email' => $salon->notification_email,
                'timezone' => $salon->timezone,
                'country' => $salon->country,
                'display_language' => $salon->display_language,
            ],
            'billing' => [
                'plan' => $salon->plan,
                'subscription_status' => $salon->subscription_status,
                'stripe_customer_id' => $salon->stripe_customer_id,
                'stripe_subscription_id' => $salon->stripe_subscription_id,
                'current_period_end' => $salon->subscription_current_period_end?->toIso8601String(),
            ],
            'usage' => $this->usageLimits->usageSummary($salon),
            'whatsapp' => $this->whatsappDetails($salon->whatsappIntegration, $salon),
            'recent_activity' => [
                'bookings' => $salon->bookings()->latest()->limit(5)->get(['id', 'client_name', 'date', 'time', 'status', 'source', 'created_at']),
                'conversations' => $salon->conversations()->latest('last_message_at')->limit(5)->get(['id', 'channel', 'provider', 'status', 'intent', 'summary', 'last_message_at']),
                'messages' => ConversationMessage::query()
                    ->whereHas('conversation', fn (Builder $query) => $query->where('salon_id', $salon->id))
                    ->latest()
                    ->limit(5)
                    ->get(['id', 'conversation_id', 'direction', 'provider', 'provider_message_id', 'metadata', 'created_at']),
            ],
        ];
    }

    public function whatsappOnboarding(?string $status = WhatsappIntegration::STATUS_REQUESTED): array
    {
        $query = $this->whatsappIntegrationsQuery()
            ->with(['salon.user'])
            ->when($status && $status !== 'all', fn (Builder $query) => $query->where('status', $status))
            ->latest('requested_at')
            ->latest();

        return [
            'status' => $status ?: WhatsappIntegration::STATUS_REQUESTED,
            'items' => $query->limit(200)->get()
                ->filter(fn (WhatsappIntegration $integration) => $this->shouldShowInOnboardingStatus($integration, $status ?: WhatsappIntegration::STATUS_REQUESTED))
                ->take(100)
                ->map(fn (WhatsappIntegration $integration) => $this->onboardingRow($integration))
                ->values(),
        ];
    }

    public function usage(): array
    {
        return [
            'month' => now()->format('Y-m'),
            'items' => $this->businessesQuery()
                ->with(['user', 'whatsappIntegration'])
                ->latest()
                ->limit(100)
                ->get()
                ->map(function (Salon $salon): array {
                    $summary = $this->usageLimits->usageSummary($salon);

                    return [
                        ...$this->businessRow($salon),
                        'usage' => $summary,
                        'warnings' => $this->usageWarnings($summary),
                    ];
                })
                ->values(),
        ];
    }

    public function issues(): array
    {
        $nearLimit = $this->usage()['items']
            ->filter(fn (array $row) => count($row['warnings']) > 0)
            ->values();

        return [
            'whatsapp_requested' => WhatsappIntegration::query()
                ->with(['salon.user'])
                ->whereHas('salon.user')
                ->where('status', WhatsappIntegration::STATUS_REQUESTED)
                ->limit(100)
                ->get()
                ->filter(fn (WhatsappIntegration $integration) => $this->hasSetupRequest($integration))
                ->take(50)
                ->map(fn (WhatsappIntegration $integration) => [
                    ...$this->onboardingRow($integration),
                    'severity' => 'info',
                    'description' => 'WhatsApp activation requested.',
                    'suggested_action' => 'Review setup request, configure sender, and run activation command.',
                ])
                ->values(),
            'active_ai_disabled' => WhatsappIntegration::query()
                ->with(['salon.user'])
                ->whereHas('salon.user')
                ->where('status', WhatsappIntegration::STATUS_ACTIVE)
                ->where('ai_enabled', false)
                ->limit(50)
                ->get()
                ->map(fn (WhatsappIntegration $integration) => [
                    ...$this->onboardingRow($integration),
                    'severity' => 'warning',
                    'description' => 'WhatsApp is active but AI replies are disabled.',
                    'suggested_action' => 'Confirm whether AI should be enabled for this business.',
                ])
                ->values(),
            'active_missing_sender' => WhatsappIntegration::query()
                ->with(['salon.user'])
                ->whereHas('salon.user')
                ->where('status', WhatsappIntegration::STATUS_ACTIVE)
                ->whereNull('twilio_sender')
                ->limit(50)
                ->get()
                ->map(fn (WhatsappIntegration $integration) => [
                    ...$this->onboardingRow($integration),
                    'severity' => 'error',
                    'description' => 'WhatsApp is active but no Twilio sender is stored.',
                    'suggested_action' => 'Check sender configuration and rerun the activation command if needed.',
                ])
                ->values(),
            'failed_whatsapp_messages' => $this->failedWhatsappMessages(),
            'missing_notification_email' => $this->businessesQuery()
                ->with('user')
                ->where(function (Builder $query): void {
                    $query->whereNull('notification_email')
                        ->orWhere('notification_email', '');
                })
                ->limit(50)
                ->get()
                ->map(fn (Salon $salon) => [
                    ...$this->businessRow($salon),
                    'salon_id' => $salon->id,
                    'business_name' => $salon->name,
                    'severity' => 'warning',
                    'description' => 'Business has no notification email.',
                    'suggested_action' => 'Ask the business to add a notification email in settings.',
                ])
                ->values(),
            'usage_near_limits' => $nearLimit,
            'failed_jobs' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->latest('failed_at')->limit(20)->get() : [],
        ];
    }

    private function businessesQuery(): Builder
    {
        return Salon::query()->whereHas('user');
    }

    private function whatsappIntegrationsQuery(): Builder
    {
        return WhatsappIntegration::query()->whereHas('salon.user');
    }

    private function issueSummary(): array
    {
        $issues = $this->issues();

        return [
            'whatsapp_waiting' => count($issues['whatsapp_requested']),
            'ai_disabled' => count($issues['active_ai_disabled']),
            'failed_whatsapp_messages' => count($issues['failed_whatsapp_messages']),
            'missing_notification_email' => count($issues['missing_notification_email']),
            'usage_warnings' => count($issues['usage_near_limits']),
            'failed_jobs' => count($issues['failed_jobs']),
        ];
    }

    private function businessRow(Salon $salon): array
    {
        return [
            'id' => $salon->id,
            'name' => $salon->name,
            'owner_name' => $salon->user?->name,
            'owner_email' => $salon->user?->email,
            'plan' => $salon->plan,
            'subscription_status' => $salon->subscription_status,
            'created_at' => $salon->created_at?->toIso8601String(),
            'website_chat_enabled' => (bool) $salon->widget_enabled,
            'phone_ai_status' => 'planned',
            'whatsapp' => [
                'status' => $salon->whatsappIntegration?->status ?? WhatsappIntegration::STATUS_NOT_CONNECTED,
                'requested_number' => $salon->whatsappIntegration?->requested_number,
                'display_number' => $salon->whatsappIntegration?->display_number,
                'ai_enabled' => (bool) $salon->whatsappIntegration?->ai_enabled,
            ],
        ];
    }

    private function shouldShowInOnboardingStatus(WhatsappIntegration $integration, ?string $status): bool
    {
        if (($status ?: WhatsappIntegration::STATUS_REQUESTED) !== WhatsappIntegration::STATUS_REQUESTED) {
            return true;
        }

        return $this->hasSetupRequest($integration);
    }

    private function hasSetupRequest(WhatsappIntegration $integration): bool
    {
        return filled($integration->metadata['latest_setup_request'] ?? null);
    }

    private function onboardingRow(WhatsappIntegration $integration): array
    {
        $salon = $integration->salon;

        return [
            'id' => $integration->id,
            'salon_id' => $salon?->id,
            'business_name' => $salon?->name,
            'owner_email' => $salon?->user?->email,
            'status' => $integration->status,
            'requested_number' => $integration->requested_number,
            'display_number' => $integration->display_number,
            'twilio_sender' => $integration->twilio_sender,
            'ai_enabled' => (bool) $integration->ai_enabled,
            'requested_at' => $integration->requested_at?->toIso8601String(),
            'setup_request' => $integration->metadata['latest_setup_request'] ?? null,
            'activation_command' => $this->activationCommand($salon, $integration),
        ];
    }

    private function whatsappDetails(?WhatsappIntegration $integration, Salon $salon): ?array
    {
        if (! $integration) {
            return null;
        }

        return [
            ...$this->onboardingRow($integration),
            'provider' => $integration->provider,
            'last_verified_at' => $integration->last_verified_at?->toIso8601String(),
            'activated_at' => $integration->activated_at?->toIso8601String(),
            'metadata' => $integration->metadata ?? [],
            'activation_command' => $this->activationCommand($salon, $integration),
        ];
    }

    private function activationCommand(?Salon $salon, WhatsappIntegration $integration): ?string
    {
        if (! $salon) {
            return null;
        }

        $number = $integration->requested_number ?: $integration->display_number ?: $integration->twilio_sender;
        if (! $number) {
            return null;
        }

        $sender = str_starts_with($number, 'whatsapp:') ? $number : 'whatsapp:'.$this->normalizePhone($number);

        return "php artisan yougo:whatsapp-activate {$salon->id} {$sender}";
    }

    private function normalizePhone(string $number): string
    {
        $number = trim($number);
        if (str_starts_with($number, '00')) {
            $number = '+'.substr($number, 2);
        }

        if (str_starts_with($number, '+')) {
            return '+'.preg_replace('/\D+/', '', substr($number, 1));
        }

        return '+'.preg_replace('/\D+/', '', $number);
    }

    private function usageWarnings(array $summary): array
    {
        $warnings = [];
        foreach ($summary['usage'] as $key => $used) {
            $limit = $summary['limits'][$key] ?? null;
            if (! $limit || ! is_numeric($used)) {
                continue;
            }

            $ratio = $used / $limit;
            if ($ratio >= 1) {
                $warnings[] = ['metric' => $key, 'level' => 'reached', 'used' => $used, 'limit' => $limit];
            } elseif ($ratio >= 0.8) {
                $warnings[] = ['metric' => $key, 'level' => 'near', 'used' => $used, 'limit' => $limit];
            }
        }

        return $warnings;
    }

    private function failedWhatsappMessages(): array
    {
        return ConversationMessage::query()
            ->with('conversation.salon.user')
            ->whereHas('conversation.salon.user')
            ->where('provider', 'twilio')
            ->where('direction', 'outbound')
            ->latest()
            ->limit(200)
            ->get()
            ->filter(function (ConversationMessage $message): bool {
                $metadata = $message->metadata ?? [];
                $status = $metadata['delivery']['status'] ?? $metadata['delivery_status'] ?? $metadata['status'] ?? null;

                return in_array($status, ['failed', 'undelivered'], true);
            })
            ->take(50)
            ->map(function (ConversationMessage $message): array {
                $metadata = $message->metadata ?? [];

                return [
                    'id' => $message->id,
                    'salon_id' => $message->conversation?->salon?->id,
                    'business_name' => $message->conversation?->salon?->name,
                    'owner_email' => $message->conversation?->salon?->user?->email,
                    'severity' => 'error',
                    'description' => 'Recent WhatsApp message failed or was undelivered.',
                    'suggested_action' => 'Inspect provider status, error code, and customer number.',
                    'provider_message_id' => $message->provider_message_id,
                    'status' => $metadata['delivery']['status'] ?? $metadata['delivery_status'] ?? $metadata['status'] ?? 'unknown',
                    'error_code' => $metadata['delivery']['error_code'] ?? $metadata['twilio_error_code'] ?? null,
                    'created_at' => $message->created_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    private function pagination(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'next_page_url' => $paginator->nextPageUrl(),
            'prev_page_url' => $paginator->previousPageUrl(),
        ];
    }
}
