<?php

namespace App\Http\Controllers;

use App\Models\Salon;
use App\Services\Assistant\AssistantChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WidgetController extends Controller
{
    public function __construct(private readonly AssistantChatService $assistantChatService)
    {
    }

    public function script(Request $request, string $widgetKey)
    {
        $salon = $this->resolveSalon($widgetKey);
        $this->ensureDomainAllowed($request, $salon);

        $widgetUrl = route('widget.show', $salon->widget_key);
        $position = $salon->widget_position ?: 'bottom-right';
        $primary = $salon->widget_primary_color ?: '#2563eb';
        $side = $position === 'bottom-left' ? 'left' : 'right';

        $script = <<<JS
(function () {
  if (window.__yougoWidgetLoaded_{$salon->id}) return;
  window.__yougoWidgetLoaded_{$salon->id} = true;

  var button = document.createElement('button');
  button.type = 'button';
  button.setAttribute('aria-label', 'Open YouGo assistant');
  button.innerHTML = 'Chat';
  button.style.position = 'fixed';
  button.style.{$side} = '20px';
  button.style.bottom = '20px';
  button.style.zIndex = '2147483647';
  button.style.border = '0';
  button.style.borderRadius = '999px';
  button.style.padding = '14px 18px';
  button.style.background = '{$primary}';
  button.style.color = '#fff';
  button.style.fontFamily = 'Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
  button.style.fontSize = '14px';
  button.style.fontWeight = '800';
  button.style.boxShadow = '0 18px 50px rgba(15, 23, 42, 0.22)';
  button.style.cursor = 'pointer';

  var frame = document.createElement('iframe');
  frame.src = '{$widgetUrl}';
  frame.title = 'YouGo AI receptionist';
  frame.loading = 'lazy';
  frame.style.position = 'fixed';
  frame.style.{$side} = '20px';
  frame.style.bottom = '84px';
  frame.style.width = '390px';
  frame.style.maxWidth = 'calc(100vw - 32px)';
  frame.style.height = '620px';
  frame.style.maxHeight = 'calc(100vh - 110px)';
  frame.style.border = '0';
  frame.style.borderRadius = '18px';
  frame.style.boxShadow = '0 24px 80px rgba(15, 23, 42, 0.28)';
  frame.style.zIndex = '2147483647';
  frame.style.display = 'none';
  frame.style.background = '#fff';

  button.addEventListener('click', function () {
    frame.style.display = frame.style.display === 'none' ? 'block' : 'none';
  });

  window.addEventListener('message', function (event) {
    if (event.source !== frame.contentWindow) return;
    if (!event.data || event.data.type !== 'yougo-widget:minimize') return;

    frame.style.display = 'none';
  });

  function mountYouGoWidget() {
    document.body.appendChild(frame);
    document.body.appendChild(button);
  }

  if (document.body) {
    mountYouGoWidget();
  } else {
    document.addEventListener('DOMContentLoaded', mountYouGoWidget);
  }
})();
JS;

        return response($script, 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'no-cache, max-age=0',
        ]);
    }

    public function show(Request $request, string $widgetKey): Response
    {
        $salon = $this->resolveSalon($widgetKey);
        $this->ensureDomainAllowed($request, $salon);
        $salon->load(['locations', 'services']);

        return Inertia::render('Widget/Show', [
            'salon' => $salon,
            'locale' => $salon->display_language ?? config('app.locale', 'ro'),
            'chatEndpoint' => route('widget.chat', $salon->widget_key),
        ]);
    }

    public function chat(Request $request, string $widgetKey): JsonResponse
    {
        $salon = $this->resolveSalon($widgetKey);
        $this->ensureDomainAllowed($request, $salon);

        $data = $request->validate([
            'conversation_id' => ['nullable', 'integer'],
            'messages' => ['required', 'array', 'min:1'],
            'messages.*.role' => ['required', Rule::in(['user', 'assistant'])],
            'messages.*.content' => ['required', 'string', 'max:3000'],
            'known_contact' => ['nullable', 'array'],
            'known_contact.name' => ['nullable', 'string', 'max:255'],
            'known_contact.phone' => ['nullable', 'string', 'max:50'],
        ]);

        $result = $this->assistantChatService->handle($salon, $data, 'web_widget');

        return response()->json($result['body'], $result['status']);
    }

    public function updateSettings(Request $request)
    {
        $salon = $request->user()->salon;
        abort_unless($salon, 404);

        $data = $request->validate([
            'widget_enabled' => ['required', 'boolean'],
            'widget_allowed_domains' => ['nullable', 'array'],
            'widget_allowed_domains.*' => ['nullable', 'string', 'max:255'],
            'widget_primary_color' => ['nullable', 'string', 'max:20'],
            'widget_position' => ['nullable', Rule::in(['bottom-right', 'bottom-left'])],
        ]);

        $salon->update([
            'widget_enabled' => $data['widget_enabled'],
            'widget_allowed_domains' => $this->normalizeDomains($data['widget_allowed_domains'] ?? []),
            'widget_primary_color' => $data['widget_primary_color'] ?: null,
            'widget_position' => $data['widget_position'] ?: 'bottom-right',
        ]);

        return back()->with('success', __('Widget settings saved successfully.'));
    }

    private function resolveSalon(string $widgetKey): Salon
    {
        $salon = Salon::query()
            ->where('widget_key', $widgetKey)
            ->where('widget_enabled', true)
            ->first();

        abort_unless($salon, 404);

        return $salon;
    }

    private function ensureDomainAllowed(Request $request, Salon $salon): void
    {
        $allowed = array_filter($salon->widget_allowed_domains ?? []);
        if (count($allowed) === 0) {
            return;
        }

        $host = $this->requestHost($request);
        $message = ($salon->display_language ?? config('app.locale')) === 'en'
            ? 'This widget is not enabled for this domain.'
            : 'Widgetul nu este activ pentru acest domeniu.';

        abort_unless($host && in_array($host, $allowed, true), 403, $message);
    }

    private function requestHost(Request $request): ?string
    {
        $source = $request->headers->get('Origin') ?: $request->headers->get('Referer');
        if (! $source) {
            return null;
        }

        $host = parse_url($source, PHP_URL_HOST) ?: $source;

        return $host ? Str::lower(preg_replace('/^www\./', '', trim($host))) : null;
    }

    private function normalizeDomains(array $domains): array
    {
        return collect($domains)
            ->map(function ($domain) {
                $domain = trim((string) $domain);
                if ($domain === '') {
                    return null;
                }

                $host = parse_url($domain, PHP_URL_HOST) ?: $domain;

                return Str::lower(preg_replace('/^www\./', '', trim($host)));
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
