<?php

namespace App\Support;

use InvalidArgumentException;
use RuntimeException;

class YouGoServices
{
    public const STATUS_LIVE = 'live';
    public const STATUS_PLANNED = 'planned';

    public const WEBSITE_CHAT = 'website_chat';
    public const WHATSAPP_AI = 'whatsapp_ai';
    public const PHONE_AI = 'phone_ai';

    private const PLAN_ALIASES = [
        'connect' => 'chat_whatsapp',
        'voice' => 'voice_starter',
        'enterprise' => 'voice_pro',
    ];

    /** @return array<int, array<string, mixed>> */
    public static function all(): array
    {
        self::validateServices();

        $services = array_values(config('yougo_services', []));
        usort($services, fn (array $a, array $b) => ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0));

        return $services;
    }

    /** @return array<string, mixed>|null */
    public static function find(string $serviceKey): ?array
    {
        self::validateServices();

        return config("yougo_services.{$serviceKey}");
    }

    /** @return array<string, mixed> */
    public static function mustFind(string $serviceKey): array
    {
        $service = self::find($serviceKey);

        if (! $service) {
            throw new InvalidArgumentException("Unknown service [{$serviceKey}].");
        }

        return $service;
    }

    /** @param array<string, mixed>|string|null $plan */
    public static function planKey(array|string|null $plan): string
    {
        if (is_array($plan)) {
            $plan = $plan['key'] ?? null;
        }

        if (! $plan) {
            return 'free';
        }

        return self::PLAN_ALIASES[$plan] ?? $plan;
    }

    /** @param array<string, mixed>|string|null $plan */
    public static function planServiceKeys(array|string|null $plan): array
    {
        if (is_array($plan)) {
            return array_values($plan['service_keys'] ?? []);
        }

        $planKey = self::planKey($plan);

        return array_values(config("yougo_plans.{$planKey}.service_keys", []));
    }

    /** @param array<string, mixed>|string|null $plan */
    public static function servicesForPlan(array|string|null $plan): array
    {
        $keys = self::planServiceKeys($plan);

        return array_values(array_filter(
            self::all(),
            fn (array $service) => in_array($service['key'], $keys, true)
        ));
    }

    /** @param array<string, mixed>|string|null $plan */
    public static function planHasService(array|string|null $plan, string $serviceKey): bool
    {
        return in_array($serviceKey, self::planServiceKeys($plan), true);
    }

    public static function isServiceLive(string $serviceKey): bool
    {
        return self::serviceStatus($serviceKey) === self::STATUS_LIVE;
    }

    public static function isServicePlanned(string $serviceKey): bool
    {
        return self::serviceStatus($serviceKey) === self::STATUS_PLANNED;
    }

    public static function serviceStatus(string $serviceKey): string
    {
        return self::mustFind($serviceKey)['implementation_status'];
    }

    public static function planHasWebsiteChat(array|string|null $plan): bool
    {
        return self::planHasService($plan, self::WEBSITE_CHAT);
    }

    public static function planHasWhatsappAi(array|string|null $plan): bool
    {
        return self::planHasService($plan, self::WHATSAPP_AI);
    }

    public static function planHasPhoneAi(array|string|null $plan): bool
    {
        return self::planHasService($plan, self::PHONE_AI);
    }

    /** @return array<int, array<string, mixed>> */
    public static function plans(): array
    {
        self::validatePlans();

        return array_values(array_map(
            fn (array $plan) => [
                ...$plan,
                'services' => self::servicesForPlan($plan),
            ],
            config('yougo_plans', [])
        ));
    }

    public static function validatePlans(): void
    {
        self::validateServices();

        foreach (config('yougo_plans', []) as $planKey => $plan) {
            foreach ($plan['service_keys'] ?? [] as $serviceKey) {
                if (! self::find($serviceKey)) {
                    throw new RuntimeException("Plan [{$planKey}] references unknown service [{$serviceKey}].");
                }
            }
        }
    }

    private static function validateServices(): void
    {
        $allowedStatuses = [self::STATUS_LIVE, self::STATUS_PLANNED];

        foreach (config('yougo_services', []) as $serviceKey => $service) {
            foreach (['key', 'title_key', 'subtitle_key', 'short_label_key', 'icon', 'implementation_status', 'category', 'entitlement_key', 'sort_order'] as $field) {
                if (! array_key_exists($field, $service)) {
                    throw new RuntimeException("Service [{$serviceKey}] is missing required field [{$field}].");
                }
            }

            if ($service['key'] !== $serviceKey) {
                throw new RuntimeException("Service [{$serviceKey}] has mismatched key [{$service['key']}].");
            }

            if (! in_array($service['implementation_status'], $allowedStatuses, true)) {
                throw new RuntimeException("Service [{$serviceKey}] has unsupported implementation status [{$service['implementation_status']}].");
            }
        }
    }
}
