<?php

namespace App\Services\Modes\Appointment;

use App\Models\Salon;
use App\Models\Service;

class AppointmentPromptContextBuilder
{
    public function __construct(
        private readonly AppointmentRequiredFieldsResolver $requiredFieldsResolver,
    ) {
    }

    public function build(Salon $salon): string
    {
        return collect([
            'Appointment mode este activ pentru acest business.',
            'Locatii si orar: '.($this->locationDetails($salon) ?: 'nu exista locatii configurate').'.',
            'Servicii oferite: '.($this->serviceDetails($salon) ?: 'nu exista servicii configurate').'.',
            'Categorii disponibile: '.($this->categoryDetails($salon) ?: 'nu exista categorii configurate').'.',
            'Staff disponibil: '.($this->staffDetails($salon) ?: 'nu exista staff configurat').'.',
            'Campuri obligatorii pentru programare: '.implode(', ', $this->requiredFieldsResolver->resolve($salon)).'.',
            $this->bookingPolicy($salon),
            'Daca clientul cere un anumit membru al echipei, foloseste staff_id doar daca acel ID este listat la serviciul selectat. Nu inventa niciodata staff_id si nu transforma numele legacy de staff in ID.',
            'Nu ghici capacitatea. Sistemul valideaza automat capacitatea locatiei si serviciului inainte de crearea programarii.',
            'Daca o ora ceruta nu este disponibila sau clientul intreaba ce ore sunt libere, foloseste checkAvailability cu service_id, location_id, date si optional staff_id. Propune doar sloturile returnate de sistem si nu inventa disponibilitate.',
            $this->knowledgeRules(),
        ])->filter()->implode(' ');
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
                    $location->max_concurrent_bookings ? "capacitate maxima simultana: {$location->max_concurrent_bookings}" : "capacitate maxima simultana: implicit 1",
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

                return collect([
                    "ID {$service->id}: {$service->name}",
                    $service->type ? "categorie: {$service->type}" : null,
                    $staff ? "staff: {$staff}" : null,
                    filled($service->price) ? "pret sau tarif: {$service->price}" : null,
                    $service->duration ? "durata: {$service->duration} minute" : null,
                    $service->max_concurrent_bookings ? "capacitate maxima simultana serviciu: {$service->max_concurrent_bookings}" : "capacitate maxima simultana serviciu: implicit 1",
                    "disponibil la locatiile ID: {$locationIds}",
                    filled($service->notes) ? "note: {$service->notes}" : null,
                ])->filter()->implode(', ');
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
                    $locations = $staffMember->relationLoaded('locations') && $staffMember->locations->isNotEmpty()
                        ? $staffMember->locations->pluck('name')->filter()->implode(', ')
                        : $staffMember->location?->name;

                    return collect([
                        "ID {$staffMember->id}: {$staffMember->name}",
                        $staffMember->role ? "rol: {$staffMember->role}" : null,
                        $locations ? "locatii: {$locations}" : null,
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

    private function bookingPolicy(Salon $salon): string
    {
        if (! ($salon->ai_booking_enabled ?? true)) {
            return 'Politica programari: AI booking este dezactivat. Nu crea programari si nu folosi functia bookBooking. Poti raspunde la intrebari despre business, servicii, preturi, locatie si program.';
        }

        $phoneRule = ($salon->ai_collect_phone ?? true)
            ? 'Cere telefonul clientului inainte de creare.'
            : 'Telefonul clientului este optional.';

        $confirmationRule = ($salon->booking_confirmations ?? true)
            ? 'Inainte sa folosesti bookBooking, recapituleaza datele si cere confirmarea clientului daca nu a confirmat deja explicit. Programarile create de AI raman pending si trebuie confirmate de echipa.'
            : null;

        return collect([
            "Politica programari: {$phoneRule}",
            $confirmationRule,
            'Clientul poate specifica data in orice format natural (ex: maine, vineri, peste 2 saptamani, 10 mai) - tu calculeaza intotdeauna data exacta in format YYYY-MM-DD bazat pe data de azi.',
            'Cand mentionezi o data in conversatie, foloseste formatul natural (ex: 27 aprilie, luni 27 aprilie) fara sa afisezi anul daca este anul curent si fara sa folosesti formatul YYYY-MM-DD in text.',
            'Verifica intotdeauna ca serviciul ales este disponibil la locatia aleasa (conform ID-urilor locatiilor din descrierea serviciului).',
            'Nu propune si nu accepta programari in zile trecute sau in afara programului locatiei.',
            'Cand ai toate datele valide, foloseste functia bookBooking cu ID-urile existente, data in format YYYY-MM-DD si ora in format HH:MM, de exemplu 12:00, fara text suplimentar sau punctuatie.',
        ])->filter()->implode(' ');
    }

    private function knowledgeRules(): string
    {
        return 'Daca nu exista servicii configurate, poti raspunde in continuare folosind detaliile business, locatiile, programul, categoriile si staff-ul. Nu inventa niciodata servicii, preturi, durate, categorii, oameni sau locatii care nu exista in configurare. Pretul unui serviciu poate fi suma fixa, interval sau tarif de tip pret/ora; foloseste exact textul configurat, fara sa il rescrii sau sa adaugi automat RON daca nu este deja in valoare. Daca utilizatorul intreaba cine se ocupa de o categorie sau de un serviciu, foloseste campul de staff al serviciului daca exista, iar daca nu exista servicii poti mentiona doar lista generala de staff configurata. Daca utilizatorul intreaba ce servicii exista intr-o categorie, raspunde doar cu serviciile configurate in acea categorie; daca nu exista servicii configurate pentru categoria respectiva, spune clar asta.';
    }
}
