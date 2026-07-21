<?php

namespace App\Services\Assistant;

use App\Models\Conversation;
use App\Models\Salon;
use App\Services\Modes\Appointment\AppointmentPromptContextBuilder;
use App\Services\Modes\Appointment\AppointmentToolDefinitions;
use App\Support\AssistantChannelBehavior;
use App\Support\BusinessLocalization;
use App\Support\BusinessTaxonomy;

class GeminiPayloadBuilder
{
    public function __construct(
        private readonly AppointmentPromptContextBuilder $appointmentPromptContextBuilder,
        private readonly AppointmentToolDefinitions $appointmentToolDefinitions,
        private readonly CustomerBookingContextService $customerBookingContext,
    ) {}

    public function build(Salon $salon, array $messages, ?Conversation $conversation = null, ?array $knownContact = null): array
    {
        $salon->loadMissing(['staff.location', 'staff.locations', 'services.staffMembers']);
        $conversation?->loadMissing(['booking.location', 'booking.service', 'booking.staffMember']);

        $payload = [
            'systemInstruction' => [
                'parts' => [[
                    'text' => $this->buildSystemInstruction($salon, $conversation, $knownContact),
                ]],
            ],
            'contents' => collect($messages)->map(fn ($message) => [
                'role' => $message['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $message['content']]],
            ])->values()->all(),
            'generationConfig' => [
                'maxOutputTokens' => 1024,
                // Chat replies don't need internal reasoning — without this, gemini-2.5-flash
                // spends output-token budget "thinking" on every single message (measured:
                // more thinking tokens than the actual visible reply on a trivial question),
                // which is pure recurring cost and latency with no user-facing benefit here.
                'thinkingConfig' => ['thinkingBudget' => 0],
            ],
        ];

        $tools = $this->appointmentToolDefinitions->forSalon($salon);
        if ($tools) {
            $tools = $this->toolsForKnownWhatsappContact($tools, $conversation, $knownContact);
            $payload['tools'] = $tools;
        }

        return $payload;
    }

    private function buildSystemInstruction(Salon $salon, ?Conversation $conversation = null, ?array $knownContact = null): string
    {
        $assistantName = $this->aiAssistantName($salon);
        $today = now()->format('Y-m-d');
        $currentBookingContext = $this->currentBookingContext($conversation);
        $recentBookingContext = $this->recentCustomerBookingContext($salon, $conversation, $knownContact);
        $hasBookingContext = filled($currentBookingContext) || filled($recentBookingContext);

        return collect([
            "Esti {$assistantName}, asistentul virtual pentru {$salon->name}.",
            $this->aiBehaviorRules($salon),
            "Data de azi este {$today}.",
            'Foloseste exclusiv informatiile configurate aici pentru a raspunde.',
            'Detalii business: '.($this->businessDetails($salon) ?: 'nu sunt configurate').'.',
            $this->aiBusinessContext($salon),
            $this->aiStructuredKnowledge($salon),
            "Context produs: modul curent este {$this->businessMode($salon)}. Pentru moment aplicatia activeaza doar fluxul appointment.",
            $this->channelInstructions($conversation),
            $currentBookingContext,
            $recentBookingContext,
            $this->knownContactContext($knownContact),
            $this->modeInstructions($salon, $hasBookingContext),
            $this->ownerInstructions($salon),
        ])->filter()->implode(' ');
    }

    private function channelInstructions(?Conversation $conversation): ?string
    {
        return match (AssistantChannelBehavior::normalize($conversation?->channel)) {
            AssistantChannelBehavior::CHANNEL_WHATSAPP => 'Channel: WhatsApp. The customer is messaging inside the same real WhatsApp thread, but YouGo dashboard conversations are separated by booking flow. Never mention the website new-chat button, website widget controls, starting a separate conversation, opening a separate chat, or using the website widget UI. Pending WhatsApp bookings can be cancelled only when the backend deterministically handles a clear customer cancellation request. Do not edit, reschedule, change service, change location, or cancel confirmed bookings automatically. For changes to an existing booking, provide a phone handoff. Do not create amendment requests. If the conversation has no linked booking, continue naturally toward a new inquiry or booking. Keep replies short and natural. Raspunde natural si concis pentru WhatsApp, ideal sub 900 de caractere. Nu folosi tabele mari sau markdown lung. Pune cate o singura intrebare pe rand cand colectezi date pentru programare. Nu inventa servicii, preturi, disponibilitate sau program.',
            AssistantChannelBehavior::CHANNEL_PHONE => 'Channel: Phone. Do not mention website chat UI, buttons, links, or separate chats. Use natural spoken-style interaction. Do not edit, reschedule, or cancel existing bookings automatically.',
            default => null,
        };
    }

    private function knownContactContext(?array $knownContact): ?string
    {
        $name = trim((string) ($knownContact['name'] ?? ''));
        $phone = preg_replace('/^whatsapp:/i', '', trim((string) ($knownContact['phone'] ?? ''))) ?? '';
        $channel = AssistantChannelBehavior::normalize($knownContact['channel'] ?? null);

        if ($channel === AssistantChannelBehavior::CHANNEL_WHATSAPP && $phone !== '') {
            return "The customer phone number is already known from WhatsApp: {$phone}. Do not ask for it again unless it is missing or invalid. Use this value as client_phone for bookBooking when creating a WhatsApp booking. Numarul de telefon al clientului este deja cunoscut din WhatsApp: {$phone}. Nu il cere din nou decat daca lipseste sau este invalid.";
        }

        if ($name === '' || $phone === '') {
            return null;
        }

        return "Există date de contact folosite anterior pentru acest vizitator: {$name}, {$phone}. Nu le folosi automat. Întreabă vizitatorul dacă vrea să le refolosească. Dacă confirmă, le poți folosi ca client_name și client_phone pentru bookBooking. Previous contact details for this browser visitor are available: {$name}, {$phone}. Do not use them silently. Ask the visitor whether they want to reuse these details. If they confirm, you may use them as client_name and client_phone for bookBooking.";
    }

    private function toolsForKnownWhatsappContact(array $tools, ?Conversation $conversation, ?array $knownContact): array
    {
        $channel = AssistantChannelBehavior::normalize($knownContact['channel'] ?? $conversation?->channel);
        $phone = preg_replace('/^whatsapp:/i', '', trim((string) ($knownContact['phone'] ?? ''))) ?? '';

        if ($channel !== AssistantChannelBehavior::CHANNEL_WHATSAPP || $phone === '') {
            return $tools;
        }

        foreach ($tools as $toolIndex => $tool) {
            foreach (($tool['functionDeclarations'] ?? []) as $declarationIndex => $declaration) {
                if (($declaration['name'] ?? null) !== 'bookBooking') {
                    continue;
                }

                $required = $declaration['parameters']['required'] ?? [];
                $tools[$toolIndex]['functionDeclarations'][$declarationIndex]['parameters']['required'] = array_values(array_diff($required, ['client_phone']));
            }
        }

        return $tools;
    }

    private function currentBookingContext(?Conversation $conversation): ?string
    {
        $booking = $conversation?->booking;
        if (! $booking) {
            return null;
        }

        $behavior = AssistantChannelBehavior::for($conversation?->channel);

        if (! $behavior['allows_new_conversation_instruction'] && $booking->isArchivedForDashboard()) {
            return collect([
                'Aceasta conversatie are o programare istorica in baza de date.',
                "status programare istorica: {$booking->status}.",
                "data istorica: {$booking->date?->format('Y-m-d')}.",
                "ora istorica: {$booking->time}.",
                $booking->service ? "serviciu istoric: {$booking->service->name}." : null,
                'Programarea istorica nu mai poate fi modificata, anulata sau reprogramata automat.',
                'Nu trata conversatia ca dedicata programarii istorice.',
                'Daca utilizatorul cere un serviciu, o ora sau o programare, continua natural catre o programare noua pe acelasi canal.',
                'Nu mentiona butonul de conversatie noua, controale din widget, conversatie noua, conversatie separata, chat separat sau UI-ul widgetului website.',
            ])->filter()->implode(' ');
        }

        return collect([
            'Aceasta conversatie are deja o programare in baza de date.',
            'Statusul curent din baza de date este sursa de adevar pentru raspunsurile despre aceasta programare.',
            "status curent: {$booking->status}.",
            "data: {$booking->date?->format('Y-m-d')}.",
            "ora: {$booking->time}.",
            "client: {$booking->client_name}.",
            $booking->client_phone ? "telefon client: {$booking->client_phone}." : null,
            $booking->location ? "locatie: {$booking->location->name}." : null,
            $booking->service ? "serviciu: {$booking->service->name}." : null,
            $booking->staffMember ? "staff: {$booking->staffMember->name}." : null,
            'Daca utilizatorul intreaba daca este programat sau confirmat, foloseste statusul curent de mai sus, nu istoricul vechi al conversatiei.',
            'Aceasta conversatie este dedicata acestei programari existente.',
            'Nu apela checkAvailability sau bookBooking in aceasta conversatie pentru o programare noua.',
            $behavior['allows_new_conversation_instruction']
                ? 'Daca utilizatorul vrea o alta programare sau o discutie noua, spune-i sa apese pe + si sa inceapa o conversatie noua.'
                : 'Pentru WhatsApp, doar anularea clara a unei programari pending poate fi facuta determinist de backend. Nu modifica, nu reprograma, nu schimba serviciul sau locatia si nu crea cereri pending de modificare. Pentru schimbari la o programare existenta, directioneaza clientul catre telefonul echipei. Nu mentiona butonul de conversatie noua, controale din widget, conversatie noua, conversatie separata, chat separat sau UI-ul widgetului website.',
        ])->filter()->implode(' ');
    }

    private function recentCustomerBookingContext(Salon $salon, ?Conversation $conversation, ?array $knownContact): ?string
    {
        if ($conversation?->booking_id) {
            return null;
        }

        $channel = AssistantChannelBehavior::normalize($conversation?->channel ?? $knownContact['channel'] ?? null);
        if ($channel !== AssistantChannelBehavior::CHANNEL_WHATSAPP) {
            return null;
        }

        $context = $this->customerBookingContext->findRecentForCustomer($salon, $conversation, $knownContact);
        if (! $context) {
            return null;
        }

        return collect([
            'Recent customer booking context:',
            'Context despre ultima programare cunoscuta a acestui client in baza de date.',
            'Aceasta informatie este doar pentru raspunsuri despre programarea existenta. Nu atasa aceasta programare conversatiei curente.',
            "booking_id context: {$context['id']}.",
            "status: {$context['status']}.",
            "data: {$context['date']}.",
            "ora: {$context['time']}.",
            filled($context['service_name']) ? "serviciu: {$context['service_name']}." : null,
            filled($context['location_name']) ? "locatie: {$context['location_name']}." : null,
            filled($context['staff_name']) ? "staff: {$context['staff_name']}." : null,
            filled($context['client_name']) ? "client: {$context['client_name']}." : null,
            filled($context['client_phone']) ? "telefon client: {$context['client_phone']}." : null,
            $context['is_historical'] ? 'Aceasta programare poate fi istorica sau arhivata in dashboard.' : null,
            'Daca utilizatorul intreaba daca programarea a fost confirmata, anulata, finalizata, in asteptare, la ce data sau la ce ora era, foloseste statusul, data si ora de mai sus.',
            'Daca statusul este confirmed, spune ca in sistem programarea apare confirmata. Daca statusul este pending, spune ca in sistem apare in asteptare. Daca statusul este cancelled, spune ca apare anulata. Daca statusul este completed sau istorica, spune ca apare finalizata sau in trecut.',
            'Nu modifica, nu reprograma si nu anula automat aceasta programare gasita doar ca istoric. Pentru editari, reprogramari sau anularea unei programari confirmed, completed, cancelled sau istorice, directioneaza clientul catre telefonul echipei.',
            'Daca utilizatorul cere o programare noua, continua fluxul normal pentru o programare noua in aceasta conversatie.',
            'Do not answer generically that AI-created bookings are pending when this actual booking context exists. The database status above is the source of truth for questions about this booking.',
        ])->filter()->implode(' ');
    }

    private function businessDetails(Salon $salon): string
    {
        $country = BusinessLocalization::normalizeCountry($salon->country);

        return collect([
            "nume business: {$salon->name}",
            $salon->website ? "website: {$salon->website}" : null,
            $salon->business_phone ? "telefon business: {$salon->business_phone}" : null,
            $salon->notification_email ? "email: {$salon->notification_email}" : null,
            "tara: {$country}",
            'moneda: '.($salon->currency ?: BusinessLocalization::currencyFor($country)),
            'prefix telefon: '.($salon->phone_prefix ?: BusinessLocalization::phonePrefixFor($country)),
            'fus orar: '.($salon->timezone ?: BusinessLocalization::timezoneFor($country)),
            'format data: '.BusinessLocalization::normalizeDateFormat($salon->date_format, $country),
            'limba implicita tara: '.BusinessLocalization::defaultLanguageFor($country),
            "mod business: {$this->businessMode($salon)}",
            $salon->business_type ? "tip business: {$salon->business_type}" : null,
            $salon->display_language ? "limba preferata in dashboard: {$this->languageName($salon->display_language)}" : null,
            filled($salon->ai_business_summary) ? "descriere configurata de owner: {$salon->ai_business_summary}" : null,
        ])->filter()->implode(', ');
    }

    private function aiBusinessContext(Salon $salon): ?string
    {
        $businessType = $salon->business_type ?: 'salon-beauty';
        $categoryLabels = BusinessTaxonomy::industryLabels($businessType, $salon->ai_industry_categories ?? []);
        $customContext = collect($salon->ai_custom_context ?? [])->filter()->values()->all();
        $mainFocus = $salon->ai_main_focus
            ? (BusinessTaxonomy::findIndustry($businessType, $salon->ai_main_focus)['label'] ?? $salon->ai_main_focus)
            : null;

        if (empty($categoryLabels) && empty($customContext) && ! $mainFocus) {
            return null;
        }

        return collect([
            'Business context selected by owner:',
            ! empty($categoryLabels) ? 'categories: '.implode(', ', $categoryLabels).'.' : null,
            ! empty($customContext) ? 'custom context: '.implode(', ', $customContext).'.' : null,
            $mainFocus ? "Main focus: {$mainFocus}." : null,
            'These categories are only context; answer based on configured services, locations, staff and AI settings. Services configured in the dashboard remain the source of truth.',
        ])->filter()->implode(' ');
    }

    private function aiStructuredKnowledge(Salon $salon): ?string
    {
        $parts = collect([
            filled($salon->ai_about_business) ? "Despre business: {$salon->ai_about_business}." : null,
            filled($salon->ai_policies) ? "Politici configurate de owner: {$salon->ai_policies}." : null,
            filled($salon->ai_faq) ? "Intrebari frecvente configurate de owner: {$salon->ai_faq}." : null,
            filled($salon->ai_recommendations) ? "Ce sa recomanzi cand este relevant: {$salon->ai_recommendations}." : null,
            filled($salon->ai_avoid) ? "Ce sa eviti: {$salon->ai_avoid}." : null,
        ])->filter();

        if ($parts->isEmpty()) {
            return null;
        }

        return 'Cunostinte AI avansate configurate de owner. Foloseste aceste informatii cand sunt relevante, dar nu le afisa ca text brut si nu le lasa sa contrazica serviciile, preturile, locatiile, staff-ul sau programul configurat. '.$parts->implode(' ');
    }

    private function aiBehaviorRules(Salon $salon): string
    {
        $languageRule = match ($salon->ai_language_mode ?? 'auto') {
            'ro' => 'Raspunde intotdeauna in romana.',
            'en' => 'Raspunde intotdeauna in engleza.',
            default => 'Detecteaza automat limba in care iti scrie clientul si raspunde intotdeauna in aceeasi limba.',
        };

        $toneRule = match ($salon->ai_tone ?? 'polite') {
            'friendly' => 'Foloseste un ton prietenos si natural.',
            'professional' => 'Foloseste un ton profesional si clar.',
            'warm' => 'Foloseste un ton cald, empatic si politicos.',
            default => 'Foloseste un ton politicos si clar.',
        };

        $styleRule = match ($salon->ai_response_style ?? 'short') {
            'balanced' => 'Raspunde echilibrat, cu suficiente detalii utile.',
            'detailed' => 'Raspunde detaliat cand intrebarea cere explicatii, dar ramai concis.',
            default => 'Raspunde scurt, clar si politicos.',
        };

        $unknownRule = ($salon->ai_unknown_answer_policy ?? 'say_unknown') === 'handoff'
            ? 'Daca nu stii raspunsul din informatiile configurate, spune ca vei transmite cererea catre echipa.'
            : 'Daca nu stii raspunsul din informatiile configurate, spune clar ca nu ai acea informatie.';

        $handoff = filled($salon->ai_handoff_message)
            ? "Mesaj de handoff configurat: {$salon->ai_handoff_message}."
            : null;

        $progressiveDisclosureRule = 'Raspunsurile trebuie sa fie cat mai scurte posibil. Daca ai de oferit mai multe informatii decat incap intr-un raspuns scurt (de exemplu o lista lunga de servicii, preturi sau optiuni), nu le insira pe toate deodata. Da un raspuns scurt cu doar 2-3 exemple relevante pentru intrebare, apoi intreaba clientul daca vrea sa afle si restul (de exemplu "Vrei sa iti spun si celelalte servicii?" sau "Mai am cateva optiuni, vrei sa le vezi?"). Continua cu detaliile ramase doar daca clientul confirma explicit ca vrea mai mult.';

        return collect([$languageRule, $toneRule, $styleRule, $unknownRule, $handoff, $progressiveDisclosureRule])->filter()->implode(' ');
    }

    private function modeInstructions(Salon $salon, bool $hasBookingContext = false): string
    {
        if (! $salon->isAppointmentBased()) {
            return 'Modul curent nu este appointment. Nu crea programari si nu folosi functia bookBooking; raspunde doar informational folosind datele configurate.';
        }

        return $this->appointmentPromptContextBuilder->build($salon, $hasBookingContext);
    }

    private function ownerInstructions(Salon $salon): ?string
    {
        if (! filled($salon->ai_custom_instructions)) {
            return null;
        }

        return "Instructiuni suplimentare de la owner: {$salon->ai_custom_instructions}. Aceste instructiuni nu au voie sa contrazica regulile de siguranta, datele configurate despre servicii, preturi, locatii sau program.";
    }

    private function aiAssistantName(Salon $salon): string
    {
        return filled($salon->ai_assistant_name) ? trim($salon->ai_assistant_name) : 'Bella';
    }

    private function businessMode(Salon $salon): string
    {
        return $salon->mode ?: Salon::MODE_APPOINTMENT;
    }

    private function languageName(string $locale): string
    {
        return match ($locale) {
            'en' => 'engleza',
            'ro' => 'romana',
            default => $locale,
        };
    }
}
