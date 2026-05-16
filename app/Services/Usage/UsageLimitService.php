<?php

namespace App\Services\Usage;

use App\Models\Salon;
use App\Services\Assistant\AssistantMessageLocalizer;
use App\Support\YouGoServices;
use Illuminate\Support\Carbon;

class UsageLimitService
{
    public const LIMIT_MESSAGE_EN = "You've reached your plan limit for this month. Please upgrade your plan or contact the business directly.";
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
        $key = $this->canonicalPlanKey($salon->plan);
        $plans = config('yougo_plans', []);

        $plan = $plans[$key] ?? $plans['free'];

        return [
            ...$plan,
            'services' => YouGoServices::servicesForPlan($plan),
        ];
    }

    public function canonicalPlanKey(?string $key): string
    {
        return YouGoServices::planKey($key);
    }

    public function plans(): array
    {
        return YouGoServices::plans();
    }

    public function services(): array
    {
        return YouGoServices::all();
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
