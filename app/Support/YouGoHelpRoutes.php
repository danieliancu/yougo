<?php

namespace App\Support;

class YouGoHelpRoutes
{
    /** @return array<int, array<string, mixed>> */
    public static function all(): array
    {
        return array_values(config('yougo_help_routes', []));
    }

    /** @return array<int, array{type: string, label: string, href: string}> */
    public static function actionsFor(string $message, array $context = [], string $locale = 'ro'): array
    {
        $message = mb_strtolower($message);
        $authenticated = (bool) ($context['authenticated'] ?? false);
        $surface = $context['surface'] ?? 'public';

        $matches = collect(self::all())
            ->filter(function (array $route) use ($message, $authenticated, $surface) {
                if (($route['requires_auth'] ?? false) && ! $authenticated) {
                    return false;
                }

                if (($route['surface'] ?? null) === 'dashboard' && $surface === 'public' && ! $authenticated) {
                    return false;
                }

                foreach ($route['keywords'] ?? [] as $keyword) {
                    if ($keyword !== '' && str_contains($message, mb_strtolower($keyword))) {
                        return true;
                    }
                }

                return false;
            })
            ->take(2)
            ->values();

        if ($matches->isEmpty() && ! $authenticated && self::looksDashboardRelated($message)) {
            $routes = collect(self::all())->keyBy('key');
            $matches = collect([
                $routes->get('public.login'),
                $routes->get('public.register'),
            ])->filter()->values();
        }

        return $matches
            ->map(fn (array $route) => [
                'type' => 'navigate',
                'label' => $locale === 'en'
                    ? 'Open '.$route['title_en']
                    : 'Deschide '.$route['title_ro'],
                'href' => $route['href'],
            ])
            ->all();
    }

    public static function knowledgeLines(string $locale = 'ro'): string
    {
        $lines = ['Known help routes and navigation targets:'];

        foreach (self::all() as $route) {
            $title = $locale === 'en' ? $route['title_en'] : $route['title_ro'];
            $description = $locale === 'en' ? $route['description_en'] : $route['description_ro'];
            $auth = ! empty($route['requires_auth']) ? 'requires login' : 'public';
            $lines[] = "- {$route['key']}: {$title}; {$description}; href {$route['href']}; {$auth}.";
        }

        return implode("\n", $lines);
    }

    private static function looksDashboardRelated(string $message): bool
    {
        foreach (['dashboard', 'programari', 'programări', 'bookings', 'widget', 'servicii', 'services', 'locatii', 'locații', 'staff', 'billing', 'facturare'] as $keyword) {
            if (str_contains($message, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
