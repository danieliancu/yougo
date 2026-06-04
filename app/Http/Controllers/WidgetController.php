<?php

namespace App\Http\Controllers;

use App\Models\Salon;
use App\Services\Assistant\AssistantChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

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
        $primary = $this->widgetColor($salon->widget_primary_color);
        $ctaText = trim((string) $salon->widget_cta_text);
        $side = $position === 'bottom-left' ? 'left' : 'right';
        $align = $position === 'bottom-left' ? 'flex-start' : 'flex-end';
        $rowDirection = $position === 'bottom-left' ? 'row-reverse' : 'row';
        $widgetUrlJson = json_encode($widgetUrl, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        $primaryJson = json_encode($primary, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        $ctaTextJson = json_encode($ctaText, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

        $script = <<<JS
(function () {
  if (window.__yougoWidgetLoaded_{$salon->id}) return;
  window.__yougoWidgetLoaded_{$salon->id} = true;

  var primaryColor = {$primaryJson};
  var ctaText = {$ctaTextJson};

  var launcher = document.createElement('div');
  launcher.style.position = 'fixed';
  launcher.style.{$side} = '20px';
  launcher.style.bottom = '20px';
  launcher.style.zIndex = '2147483647';
  launcher.style.display = 'flex';
  launcher.style.alignItems = 'center';
  launcher.style.justifyContent = '{$align}';
  launcher.style.gap = '10px';
  launcher.style.flexDirection = '{$rowDirection}';
  launcher.style.fontFamily = 'Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';

  var label = null;
  if (ctaText) {
    label = document.createElement('span');
    label.textContent = ctaText;
    label.style.maxWidth = '220px';
    label.style.border = '1px solid rgba(226, 232, 240, 0.96)';
    label.style.borderRadius = '999px';
    label.style.padding = '10px 14px';
    label.style.background = '#ffffff';
    label.style.color = '#0f172a';
    label.style.fontSize = '13px';
    label.style.fontWeight = '800';
    label.style.lineHeight = '1.2';
    label.style.boxShadow = '0 14px 36px rgba(15, 23, 42, 0.18)';
    label.style.whiteSpace = 'nowrap';
    label.style.overflow = 'hidden';
    label.style.textOverflow = 'ellipsis';
  }

  var button = document.createElement('button');
  button.type = 'button';
  button.setAttribute('aria-label', 'Open YouGo assistant');
  button.setAttribute('aria-expanded', 'false');
  button.innerHTML = '<svg class="lucide lucide-message-circle" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="height:24px;width:24px;display:block"><path d="M2.992 16.342a2 2 0 0 1 .094 1.167l-1.065 3.29a1 1 0 0 0 1.236 1.168l3.413-.998a2 2 0 0 1 1.099.092 10 10 0 1 0-4.777-4.719"></path></svg><span aria-hidden="true" style="position:absolute;right:8px;top:8px;height:10px;width:10px;border-radius:999px;border:2px solid ' + primaryColor + ';background:#34d399"></span>';
  button.style.position = 'relative';
  button.style.display = 'flex';
  button.style.width = '56px';
  button.style.height = '56px';
  button.style.alignItems = 'center';
  button.style.justifyContent = 'center';
  button.style.border = '0';
  button.style.borderRadius = '16px';
  button.style.padding = '0';
  button.style.background = primaryColor;
  button.style.color = '#fff';
  button.style.boxShadow = '0 20px 40px rgba(79, 70, 229, 0.30)';
  button.style.cursor = 'pointer';
  button.style.transition = 'filter 160ms ease, transform 160ms ease';

  button.addEventListener('mouseenter', function () {
    button.style.filter = 'brightness(0.94)';
  });

  button.addEventListener('mouseleave', function () {
    button.style.filter = 'none';
  });

  var frame = document.createElement('iframe');
  frame.src = {$widgetUrlJson};
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
    var willOpen = frame.style.display === 'none';
    frame.style.display = willOpen ? 'block' : 'none';
    button.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
  });

  window.addEventListener('message', function (event) {
    if (event.source !== frame.contentWindow) return;
    if (!event.data || event.data.type !== 'yougo-widget:minimize') return;

    frame.style.display = 'none';
    button.setAttribute('aria-expanded', 'false');
  });

  function mountYouGoWidget() {
    document.body.appendChild(frame);
    if (label) launcher.appendChild(label);
    launcher.appendChild(button);
    document.body.appendChild(launcher);
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

        $response = Inertia::render('Widget/Show', [
            'salon' => $salon,
            'locale' => $salon->display_language ?? config('app.locale', 'ro'),
            'chatEndpoint' => route('widget.chat', $salon->widget_key),
        ])->toResponse($request);

        $response->headers->remove('X-Frame-Options');
        $response->headers->set('Content-Security-Policy', $this->widgetFrameAncestors($salon));

        return $response;
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
            'widget_primary_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/'],
            'widget_cta_text' => ['nullable', 'string', 'max:40'],
            'widget_position' => ['nullable', Rule::in(['bottom-right', 'bottom-left'])],
        ]);

        $salon->update([
            'widget_enabled' => $data['widget_enabled'],
            'widget_allowed_domains' => $this->normalizeDomains($data['widget_allowed_domains'] ?? []),
            'widget_primary_color' => $data['widget_primary_color'] ?: null,
            'widget_cta_text' => trim((string) ($data['widget_cta_text'] ?? '')) ?: null,
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

    private function widgetFrameAncestors(Salon $salon): string
    {
        $allowed = array_values(array_filter($salon->widget_allowed_domains ?? []));

        if (count($allowed) === 0) {
            return 'frame-ancestors *';
        }

        $ancestors = collect($allowed)
            ->flatMap(fn (string $host) => ["https://{$host}", "http://{$host}"])
            ->prepend("'self'")
            ->unique()
            ->values()
            ->implode(' ');

        return "frame-ancestors {$ancestors}";
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

    private function widgetColor(?string $color): string
    {
        $color = trim((string) $color);

        return preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $color) ? $color : '#2563eb';
    }
}
