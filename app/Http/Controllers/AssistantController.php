<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Salon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AssistantController extends Controller
{
    public function show(Salon $salon): Response
    {
        $salon->load(['locations', 'services']);

        return Inertia::render('Assistant/Show', [
            'salon' => $salon,
            'locale' => $salon->display_language ?? config('app.locale', 'ro'),
        ]);
    }

    public function abandon(Request $request, Salon $salon): JsonResponse
    {
        $conversationId = (int) $request->input('conversation_id');

        $conversation = $salon->conversations()->whereKey($conversationId)->first();

        if ($conversation && $conversation->intent !== 'booking') {
            $conversation->update(['intent' => 'abandoned', 'status' => 'completed']);
        }

        return response()->json(['ok' => true]);
    }

    public function chat(Request $request, Salon $salon): JsonResponse
    {
        $data = $request->validate([
            'conversation_id' => ['nullable', 'integer'],
            'messages' => ['required', 'array', 'min:1'],
            'messages.*.role' => ['required', Rule::in(['user', 'assistant'])],
            'messages.*.content' => ['required', 'string', 'max:3000'],
        ]);

        $salon->load(['locations', 'services']);
        $conversation = $this->resolveConversation($salon, $data['conversation_id'] ?? null);
        $latestUserMessage = collect($data['messages'])->where('role', 'user')->last();

        if ($latestUserMessage) {
            $conversation->messages()->create([
                'role' => 'user',
                'content' => $latestUserMessage['content'],
            ]);
        }

        if (! config('services.gemini.key')) {
            $this->updateConversationTiming($conversation);

            return response()->json([
                'message' => 'Gemini nu este configurat inca. Adauga GEMINI_API_KEY in .env si reporneste serverul.',
                'conversation_id' => $conversation->id,
            ], 503);
        }

        $payload = $this->buildGeminiPayload($salon, $data['messages']);
        $model = config('services.gemini.model', 'gemini-3-flash-preview');
        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

        $response = Http::withOptions([
                'proxy' => '',
                'verify' => config('services.gemini.ca_bundle'),
            ])
            ->timeout(30)
            ->post($endpoint.'?key='.config('services.gemini.key'), $payload);

        if (! $response->successful()) {
            $this->updateConversationTiming($conversation);

            return response()->json([
                'message' => 'Asistentul AI nu este disponibil momentan.',
                'details' => $response->json('error.message'),
            ], 502);
        }

        $parts = $response->json('candidates.0.content.parts', []);
        $text = $this->stripMarkdownBold(collect($parts)->pluck('text')->filter()->implode("\n"));
        $booking = null;

        foreach ($parts as $part) {
            $functionCall = $part['functionCall'] ?? null;
            if (($functionCall['name'] ?? null) === 'bookBooking') {
                try {
                    $booking = $this->bookBooking($salon, $functionCall['args'] ?? []);
                    $conversation->update([
                        'booking_id' => $booking->id,
                        'contact_name' => $booking->client_name,
                        'contact_phone' => $booking->client_phone,
                        'status' => 'completed',
                        'intent' => 'booking',
                    ]);
                    $text = sprintf(
                        'Am inregistrat programarea pentru %s la ora %s. Te vom contacta pentru confirmare.',
                        $booking->date->format('Y-m-d'),
                        $booking->time
                    );
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                    $text = $e->getMessage();
                    $booking = null;
                }
            }
        }

        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $this->stripMarkdownBold($text ?: 'Nu am inteles exact. Poti reformula?'),
        ]);

        $conversation->update([
            'summary' => $this->summarizeConversation($conversation),
        ]);
        $this->updateConversationTiming($conversation);

        return response()->json([
            'message' => $this->stripMarkdownBold($text ?: 'Nu am inteles exact. Poti reformula?'),
            'conversation_id' => $conversation->id,
            'booking' => $booking?->load(['location', 'service']),
        ]);
    }

    private function resolveConversation(Salon $salon, ?int $conversationId): Conversation
    {
        if ($conversationId) {
            $conversation = $salon->conversations()->whereKey($conversationId)->first();
            if ($conversation) {
                return $conversation;
            }
        }

        $used = $salon->conversations()->pluck('visitor_number')->filter()->sort()->values()->all();
        $next = 1;
        foreach ($used as $n) {
            if ($n > $next) break;
            $next = $n + 1;
        }

        return $salon->conversations()->create([
            'channel' => 'chat',
            'status' => 'open',
            'intent' => 'inquiry',
            'visitor_number' => $next,
            'summary' => 'Conversatie noua pornita din widgetul public.',
            'last_message_at' => now(),
        ]);
    }

    private function updateConversationTiming(Conversation $conversation): void
    {
        $now = now();
        $startedAt = $conversation->created_at ?? $now;

        $conversation->update([
            'last_message_at' => $now,
            'duration_seconds' => max(0, (int) $startedAt->diffInSeconds($now, true)),
        ]);
    }

    private function summarizeConversation(Conversation $conversation): string
    {
        $messages = $conversation->messages()->latest()->limit(6)->get()->reverse();
        $userMessages = $messages->where('role', 'user')->pluck('content')->take(3)->implode(' ');

        if ($conversation->booking_id) {
            return 'Clientul a discutat cu asistentul si a creat o programare care asteapta confirmare.';
        }

        if ($userMessages) {
            return 'Clientul a intrebat despre '.$this->shorten($userMessages, 180);
        }

        return 'Conversatie in desfasurare cu asistentul virtual.';
    }

    private function shorten(string $text, int $limit): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text));

        return strlen($text) > $limit ? substr($text, 0, $limit - 3).'...' : $text;
    }

    private function buildGeminiPayload(Salon $salon, array $messages): array
    {
        $locations = $salon->locations
            ->map(function ($location) {
                $hours = collect($location->hours ?? [])
                    ->map(fn ($value, $day) => "{$day}: {$value}")
                    ->implode(', ');

                return collect([
                    "ID {$location->id}: {$location->name}",
                    $location->address ? "adresa: {$location->address}" : null,
                    $location->phone ? "telefon: {$location->phone}" : null,
                    $location->email ? "email: {$location->email}" : null,
                    $hours ? "program: {$hours}" : null,
                ])->filter()->implode(', ');
            })
            ->implode('; ');
        $services = $salon->services
            ->map(function ($service) {
                $locationIds = ! empty($service->location_ids) ? implode(',', $service->location_ids) : 'toate locatiile';
                $staff = collect($service->staff ?? [])->filter()->implode(', ');
                $details = [
                    "ID {$service->id}: {$service->name}",
                    $service->type ? "categorie: {$service->type}" : null,
                    $staff ? "staff: {$staff}" : null,
                    filled($service->price) ? "pret sau tarif: {$service->price}" : null,
                    $service->duration ? "durata: {$service->duration} minute" : null,
                    "disponibil la locatiile ID: {$locationIds}",
                    filled($service->notes) ? "note: {$service->notes}" : null,
                ];

                return collect($details)->filter()->implode(', ');
            })
            ->implode('; ');
        $categories = collect($salon->service_categories ?? [])->filter()->implode(', ');
        $staff = collect($salon->service_staff ?? [])->filter()->implode(', ');

        $today = now()->format('Y-m-d');

        $businessDetails = collect([
            "nume business: {$salon->name}",
            $salon->industry ? "industrie: {$salon->industry}" : null,
            $salon->website      ? "website: {$salon->website}"           : null,
            $salon->business_phone ? "telefon business: {$salon->business_phone}" : null,
            $salon->notification_email ? "email: {$salon->notification_email}" : null,
            $salon->country      ? "tara: {$salon->country}"              : null,
            $salon->display_language ? "limba preferata in dashboard: {$this->languageName($salon->display_language)}" : null,
        ])->filter()->implode(', ');

        return [
            'systemInstruction' => [
                'parts' => [[
                    'text' => "Esti Bella, asistentul virtual pentru {$salon->name}. Detecteaza automat limba in care iti scrie clientul si raspunde intotdeauna in aceeasi limba. Raspunde scurt, clar si politicos. Data de azi este {$today}. Foloseste exclusiv informatiile configurate aici pentru a raspunde: detalii business: ".($businessDetails ?: 'nu sunt configurate').". Locatii si orar: ".($locations ?: 'nu exista locatii configurate').". Servicii oferite: ".($services ?: 'nu exista servicii configurate').". Categorii disponibile: ".($categories ?: 'nu exista categorii configurate').". Staff disponibil: ".($staff ?: 'nu exista staff configurat').". Daca nu exista servicii configurate, poti raspunde in continuare folosind detaliile business, locatiile, programul, categoriile si staff-ul. Nu inventa niciodata servicii, preturi, durate, categorii, oameni sau locatii care nu exista in configurare. Pretul unui serviciu poate fi suma fixa, interval sau tarif de tip pret/ora; foloseste exact textul configurat, fara sa il rescrii sau sa adaugi automat RON daca nu este deja in valoare. Daca utilizatorul intreaba cine se ocupa de o categorie sau de un serviciu, foloseste campul de staff al serviciului daca exista, iar daca nu exista servicii poti mentiona doar lista generala de staff configurata. Daca utilizatorul intreaba ce servicii exista intr-o categorie, raspunde doar cu serviciile configurate in acea categorie; daca nu exista servicii configurate pentru categoria respectiva, spune clar asta. Pentru programari cere nume, telefon, serviciu, locatie, data si ora. Clientul poate specifica data in orice format natural (ex: maine, vineri, peste 2 saptamani, 10 mai) - tu calculeaza intotdeauna data exacta in format YYYY-MM-DD bazat pe data de azi. Cand mentionezi o data in conversatie, foloseste formatul natural (ex: 27 aprilie, luni 27 aprilie) fara sa afisezi anul daca este anul curent si fara sa folosesti formatul YYYY-MM-DD in text. Verifica intotdeauna ca serviciul ales este disponibil la locatia aleasa (conform ID-urilor locatiilor din descrierea serviciului). Nu propune si nu accepta programari in zile trecute sau in afara programului locatiei. Cand ai toate datele valide, foloseste functia bookBooking cu ID-urile existente si data in format YYYY-MM-DD.",
                ]],
            ],
            'contents' => collect($messages)->map(fn ($message) => [
                'role' => $message['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $message['content']]],
            ])->values()->all(),
            'tools' => [[
                'functionDeclarations' => [[
                    'name' => 'bookBooking',
                    'description' => 'Creeaza o programare pending in baza de date.',
                    'parameters' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'client_name' => ['type' => 'STRING'],
                            'client_phone' => ['type' => 'STRING'],
                            'location_id' => ['type' => 'STRING'],
                            'service_id' => ['type' => 'STRING'],
                            'date' => ['type' => 'STRING'],
                            'time' => ['type' => 'STRING'],
                        ],
                        'required' => ['client_name', 'location_id', 'service_id', 'date', 'time'],
                    ],
                ]],
            ]],
        ];
    }

    private function bookBooking(Salon $salon, array $args): Booking
    {
        $locationId = (int) Arr::get($args, 'location_id');
        $serviceId = (int) Arr::get($args, 'service_id');
        $dateStr = (string) Arr::get($args, 'date');
        $timeStr = substr((string) Arr::get($args, 'time'), 0, 5);

        $location = $salon->locations()->whereKey($locationId)->first();
        abort_unless($location, 422, 'Locatia nu apartine salonului.');

        $service = $salon->services()->whereKey($serviceId)->first();
        abort_unless($service, 422, 'Serviciul nu apartine salonului.');

        $locationIds = $service->location_ids ?? [];
        if (! empty($locationIds)) {
            abort_unless(in_array($locationId, $locationIds), 422, "Serviciul {$service->name} nu este disponibil la locatia {$location->name}.");
        }

        $date = \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $dateStr);
        abort_unless($date && $date->format('Y-m-d') === $dateStr, 422, 'Data programarii este invalida.');
        abort_unless($date->startOfDay()->gte(now()->startOfDay()), 422, 'Nu se pot face programari in trecut.');

        abort_unless(preg_match('/^\d{2}:\d{2}$/', $timeStr), 422, 'Formatul orei este invalid.');

        $dayKey = strtolower($date->format('D'));
        $hours = $location->hours ?? [];
        $dayHours = $hours[$dayKey] ?? null;

        if ($dayHours && stripos($dayHours, 'inchis') !== false) {
            abort(422, "Locatia {$location->name} este inchisa in ziua selectata.");
        }

        if ($dayHours && preg_match('/(\d{2}:\d{2})\s*-\s*(\d{2}:\d{2})/', $dayHours, $m)) {
            abort_unless($timeStr >= $m[1] && $timeStr < $m[2], 422, "Ora {$timeStr} este in afara programului locatiei ({$dayHours}).");
        }

        return $salon->bookings()->create([
            'location_id' => $locationId,
            'service_id' => $serviceId,
            'client_name' => (string) Arr::get($args, 'client_name'),
            'client_phone' => (string) Arr::get($args, 'client_phone', ''),
            'date' => $dateStr,
            'time' => $timeStr,
            'status' => 'pending',
        ]);
    }

    private function languageName(string $locale): string
    {
        return match ($locale) {
            'en' => 'engleza',
            'ro' => 'romana',
            default => $locale,
        };
    }

    private function stripMarkdownBold(string $text): string
    {
        return str_replace('**', '', $text);
    }
}
