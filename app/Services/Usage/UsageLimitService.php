<?php

namespace App\Services\Usage;

use App\Models\Salon;
use App\Services\Assistant\AssistantMessageLocalizer;
use Illuminate\Support\Carbon;

class UsageLimitService
{
    public const LIMIT_MESSAGE_EN = "You've reached your plan limit for this month. Please upgrade your plan or contact the business directly.";
    private const PLAN_ALIASES = [
        'connect' => 'chat_whatsapp',
        'voice' => 'voice_starter',
        'enterprise' => 'voice_pro',
    ];
    public const LIMIT_MESSAGE_RO = 'Ai atins limita planului pentru această lună. Te rugăm să faci upgrade sau să contactezi direct businessul.';

    public function __construct(private readonly AssistantMessageLocalizer $messageLocalizer)
    {
    }

    public function getCurrentMonthlyUsage(Salon $salon): array
    {
        $start = Carbon::now()->startOfMonth();
        $end = Carbon::now()->addMonthNoOverflow()->startOfMonth();

        $totals = $salon->usageEvents()
            ->where('occurred_at', '>=', $start)
            ->where('occurred_at', '<', $end)
            ->selectRaw('event_type, SUM(quantity) as total')
            ->groupBy('event_type')
            ->pluck('total', 'event_type');

        return [
            'conversations' => (int) ($totals['conversation_started'] ?? 0),
            'ai_messages' => (int) ($totals['ai_message'] ?? 0),
            'bookings' => (int) ($totals['booking_created'] ?? 0),
        ];
    }

    public function getPlanLimits(Salon $salon): array
    {
        $plans = config('yougo_plans', []);
        $key = $this->canonicalPlanKey($salon->plan);

        return $plans[$key] ?? $plans['free'];
    }

    public function canonicalPlanKey(?string $key): string
    {
        if (! $key) {
            return 'free';
        }

        return self::PLAN_ALIASES[$key] ?? $key;
    }

    public function plans(): array
    {
        $this->assertPlanServiceKeysExist();

        return array_values(config('yougo_plans', []));
    }

    public function services(): array
    {
        return array_values(config('yougo_services', []));
    }

    private function assertPlanServiceKeysExist(): void
    {
        $serviceKeys = array_keys(config('yougo_services', []));

        foreach (config('yougo_plans', []) as $planKey => $plan) {
            foreach ($plan['service_keys'] ?? [] as $serviceKey) {
                if (! in_array($serviceKey, $serviceKeys, true)) {
                    throw new \RuntimeException("Unknown service key [{$serviceKey}] configured on plan [{$planKey}].");
                }
            }
        }
    }

    public function canStartConversation(Salon $salon): bool
    {
        $usage = $this->getCurrentMonthlyUsage($salon);
        $limits = $this->getPlanLimits($salon);

        return $this->isWithinLimit($usage['conversations'], $limits['monthly_conversations']);
    }

    public function canSendAiMessage(Salon $salon): bool
    {
        $usage = $this->getCurrentMonthlyUsage($salon);
        $limits = $this->getPlanLimits($salon);

        return $this->isWithinLimit($usage['ai_messages'], $limits['monthly_ai_messages']);
    }

    public function canCreateBooking(Salon $salon): bool
    {
        $usage = $this->getCurrentMonthlyUsage($salon);
        $limits = $this->getPlanLimits($salon);

        return $this->isWithinLimit($usage['bookings'], $limits['monthly_bookings']);
    }

    public function usageSummary(Salon $salon): array
    {
        $usage = $this->getCurrentMonthlyUsage($salon);
        $limits = $this->getPlanLimits($salon);

        return [
            'plan' => $limits,
            'usage' => $usage,
            'limits' => [
                'conversations' => $limits['monthly_conversations'],
                'ai_messages' => $limits['monthly_ai_messages'],
                'bookings' => $limits['monthly_bookings'],
            ],
        ];
    }

    public function limitMessage(Salon $salon): string
    {
        return $this->messageLocalizer->limitMessage($salon);
    }

    private function isWithinLimit(int $used, ?int $limit): bool
    {
        return $limit === null || $used < $limit;
    }
}
