<?php

namespace App\Services\Assistant;

use App\Models\Salon;
use App\Models\Service;

class GeminiPayloadBuilder
{
    public function build(Salon $salon, array $messages): array
    {
        $salon->loadMissing(['staff.location', 'services.staffMembers']);

        $payload = [
            'systemInstruction' => [
                'parts' => [[
                    'text' => $this->buildSystemInstruction($salon),
                ]],
            ],
            'contents' => collect($messages)->map(fn ($message) => [
                'role' => $message['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $message['content']]],
            ])->values()->all(),
        ];

        if ($salon->isAppointmentBased() && $this->aiBookingEnabled($salon)) {
            $payload['tools'] = [[
                'functionDeclarations' => [[
                    'name' => 'bookBooking',
                    'description' => 'Creeaza o programare pending in baza de date. Trimite date in format strict: date=YYYY-MM-DD, time=HH:MM.',
                    'parameters' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'client_name' => ['type' => 'STRING'],
                            'client_phone' => ['type' => 'STRING'],
                            'location_id' => ['type' => 'STRING'],
                            'service_id' => ['type' => 'STRING'],
                            'staff_id' => ['type' => 'STRING'],
                            'date' => ['type' => 'STRING'],
                            'time' => ['type' => 'STRING'],
                        ],
                        'required' => ['client_name', 'location_id', 'service_id', 'date', 'time'],
                    ],
                ]],
            ]];
        }

        return $payload;
    }

    private function buildSystemInstruction(Salon $salon): string
    {
        $assistantName = $this->aiAssistantName($salon);
        $today = now()->format('Y-m-d');

        return collect([
            "Esti {$assistantName}, asistentul virtual pentru {$salon->name}.",
            $this->aiBehaviorRules($salon),
            "Data de azi este {$today}.",
            "Foloseste exclusiv informatiile configurate aici pentru a raspunde.",
            "Detalii business: ".($this->businessDetails($salon) ?: 'nu sunt configurate').'.',
            "Context produs: modul curent este {$this->businessMode($salon)}. Pentru moment aplicatia activeaza doar fluxul appointment.",
            "Locatii si orar: ".($this->locationDetails($salon) ?: 'nu exista locatii configurate').'.',
            "Servicii oferite: ".($this->serviceDetails($salon) ?: 'nu exista servicii configurate').'.',
            "Categorii disponibile: ".($this->categoryDetails($salon) ?: 'nu exista categorii configurate').'.',
            "Staff disponibil: ".($this->staffDetails($salon) ?: 'nu exista staff configurat').'.',
            $this->knowledgeRules(),
            $this->bookingRules($salon),
            $this->ownerInstructions($salon),
        ])->filter()->implode(' ');
    }

    private function businessDetails(Salon $salon): string
    {
        return collect([
            "nume business: {$salon->name}",
            $salon->industry ? "industrie: {$salon->industry}" : null,
            $salon->website ? "website: {$salon->website}" : null,
            $salon->business_phone ? "telefon business: {$salon->business_phone}" : null,
            $salon->notification_email ? "email: {$salon->notification_email}" : null,
            $salon->country ? "tara: {$salon->country}" : null,
            "mod business: {$this->businessMode($salon)}",
            $salon->business_type ? "tip business: {$salon->business_type}" : null,
            $salon->display_language ? "limba preferata in dashboard: {$this->languageName($salon->display_language)}" : null,
            filled($salon->ai_business_summary) ? "descriere configurata de owner: {$salon->ai_business_summary}" : null,
        ])->filter()->implode(', ');
    }

    private function locationDetails(Salon $salon): string
    {
        return $salon->locations
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
    }

    private function serviceDetails(Salon $salon): string
    {
        return $salon->services
            ->map(function ($service) {
                $locationIds = ! empty($service->location_ids) ? implode(',', $service->location_ids) : 'toate locatiile';
                $staff = $this->serviceStaffDetails($service);
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
    }

    private function categoryDetails(Salon $salon): string
    {
        return collect($salon->service_categories ?? [])->filter()->implode(', ');
    }

    private function staffDetails(Salon $salon): string
    {
        $staff = $salon->staff->where('active', true);

        if ($staff->isNotEmpty()) {
            return $staff
                ->map(function ($staffMember) {
                    return collect([
                        "ID {$staffMember->id}: {$staffMember->name}",
                        $staffMember->role ? "rol: {$staffMember->role}" : null,
                        $staffMember->location ? "locatie: {$staffMember->location->name}" : null,
                        $staffMember->email ? "email: {$staffMember->email}" : null,
                        $staffMember->phone ? "telefon: {$staffMember->phone}" : null,
                    ])->filter()->implode(', ');
                })
                ->implode('; ');
        }

        return collect($salon->service_staff ?? [])->filter()->implode(', ');
    }

    private function serviceStaffDetails(Service $service): string
    {
        $staffMembers = $service->staffMembers->where('active', true);

        if ($staffMembers->isNotEmpty()) {
            return $staffMembers
                ->map(fn ($staffMember) => collect([
                    "ID {$staffMember->id}: {$staffMember->name}",
                    $staffMember->role ? "rol: {$staffMember->role}" : null,
                ])->filter()->implode(' / '))
                ->implode(', ');
        }

        return collect($service->staff ?? [])->filter()->implode(', ');
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

        return collect([$languageRule, $toneRule, $styleRule, $unknownRule, $handoff])->filter()->implode(' ');
    }

    private function knowledgeRules(): string
    {
        return 'Daca nu exista servicii configurate, poti raspunde in continuare folosind detaliile business, locatiile, programul, categoriile si staff-ul. Nu inventa niciodata servicii, preturi, durate, categorii, oameni sau locatii care nu exista in configurare. Pretul unui serviciu poate fi suma fixa, interval sau tarif de tip pret/ora; foloseste exact textul configurat, fara sa il rescrii sau sa adaugi automat RON daca nu este deja in valoare. Daca utilizatorul intreaba cine se ocupa de o categorie sau de un serviciu, foloseste campul de staff al serviciului daca exista, iar daca nu exista servicii poti mentiona doar lista generala de staff configurata. Daca utilizatorul intreaba ce servicii exista intr-o categorie, raspunde doar cu serviciile configurate in acea categorie; daca nu exista servicii configurate pentru categoria respectiva, spune clar asta.';
    }

    private function bookingRules(Salon $salon): string
    {
        if (! $salon->isAppointmentBased()) {
            return 'Modul curent nu este appointment. Nu crea programari si nu folosi functia bookBooking; raspunde doar informational folosind datele configurate.';
        }

        if (! $this->aiBookingEnabled($salon)) {
            return 'Nu crea programari si nu folosi functia bookBooking. Poti raspunde la intrebari despre business, servicii, preturi, locatie si program.';
        }

        $phoneRule = $this->aiCollectPhone($salon)
            ? 'Pentru programari cere nume, telefon, serviciu, locatie, data si ora.'
            : 'Pentru programari cere nume, serviciu, locatie, data si ora; telefonul este optional.';

        return "{$phoneRule} Clientul poate specifica data in orice format natural (ex: maine, vineri, peste 2 saptamani, 10 mai) - tu calculeaza intotdeauna data exacta in format YYYY-MM-DD bazat pe data de azi. Cand mentionezi o data in conversatie, foloseste formatul natural (ex: 27 aprilie, luni 27 aprilie) fara sa afisezi anul daca este anul curent si fara sa folosesti formatul YYYY-MM-DD in text. Verifica intotdeauna ca serviciul ales este disponibil la locatia aleasa (conform ID-urilor locatiilor din descrierea serviciului). Nu propune si nu accepta programari in zile trecute sau in afara programului locatiei. Cand ai toate datele valide, foloseste functia bookBooking cu ID-urile existente, data in format YYYY-MM-DD si ora in format HH:MM, de exemplu 12:00, fara text suplimentar sau punctuatie.";
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

    private function aiBookingEnabled(Salon $salon): bool
    {
        return $salon->ai_booking_enabled ?? true;
    }

    private function aiCollectPhone(Salon $salon): bool
    {
        return $salon->ai_collect_phone ?? true;
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
