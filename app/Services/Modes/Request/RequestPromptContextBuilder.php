<?php

namespace App\Services\Modes\Request;

use App\Models\Salon;
use App\Support\BusinessTaxonomy;

/**
 * Mirrors AppointmentPromptContextBuilder for the `request` capability: tells the AI what
 * a Request is for this business (fields to collect, urgency rules, safety instructions),
 * driven entirely by the business type's Task-4 capability schema (see
 * App\Support\BusinessTaxonomy::CAPABILITY_SCHEMA) — no hardcoded per-industry copy here.
 */
class RequestPromptContextBuilder
{
    public function build(Salon $salon): string
    {
        $businessType = BusinessTaxonomy::findBusinessType($salon->business_type ?: 'other') ?? BusinessTaxonomy::findBusinessType('other');

        return collect([
            'Request mode este activ pentru acest business — clientii pot lasa o solicitare (cerere de oferta, interventie, callback, diagnostic, verificare de status sau informatii) in loc de o programare exacta.',
            'Solicitarea nu este doar o alternativa la programare: foloseste-o de fiecare data cand clientul are nevoie de o verificare, o actiune sau un raspuns ulterior din partea echipei la care tu nu poti raspunde direct din informatiile configurate (de exemplu stadiul unui serviciu, al unei comenzi, al unui document, al unui dosar sau al unui rezultat).',
            'Informatii de colectat pentru o solicitare: '.$this->collectedFieldsLine($businessType).'.',
            $this->conditionalFieldsLine($businessType),
            $this->urgencyRulesLine($businessType),
            filled($businessType['safety_instructions'] ?? null) ? "Reguli de siguranta: {$businessType['safety_instructions']}" : null,
            'Confidentialitate: nu colecta rezultate, diagnostice, CNP sau alte date sensibile (medicale, financiare, juridice etc.) decat daca sunt strict necesare pentru solicitare, si nu comunica niciodata astfel de informatii sensibile prin chat inainte ca identitatea clientului sa fie verificata de echipa companiei. Nu pretinde si nu lasa de inteles ca identitatea clientului a fost deja verificata.',
            'Nu promite o programare confirmata, o ora exacta sau disponibilitate garantata — o solicitare inseamna ca echipa va reveni catre client, nu ca cererea e deja acceptata.',
            'Cand ai adunat informatiile relevante si clientul e de acord sa lase solicitarea, foloseste functia createRequest cu type (general, quote, job, callback, diagnostic sau information), title, description, priority (normal, high sau urgent — urgent doar daca regulile de urgenta de mai sus se aplica), client_name, client_phone si, daca sunt relevante, location_id, service_id, preferred_date, preferred_window. Orice alta informatie specifica colectata trimite-o ca JSON in structured_data.',
            'Daca clientul refuza sa lase o solicitare, ofera-i in schimb datele de contact ale businessului ca alternativa, fara sa insisti.',
            'Nu inventa servicii, locatii, preturi sau disponibilitate care nu exista in configurare.',
        ])->filter()->implode(' ');
    }

    private function collectedFieldsLine(array $businessType): string
    {
        $fields = $businessType['collected_fields'] ?? [];

        return $fields === [] ? 'numele si telefonul clientului, plus o descriere a cererii' : implode(', ', $fields);
    }

    private function conditionalFieldsLine(array $businessType): ?string
    {
        $conditional = $businessType['conditional_fields'] ?? [];

        if ($conditional === []) {
            return null;
        }

        $lines = collect($conditional)
            ->map(fn ($condition, $field) => "{$field} ({$condition})")
            ->implode('; ');

        return "Cere aceste campuri doar cand se aplica: {$lines}.";
    }

    private function urgencyRulesLine(array $businessType): ?string
    {
        $rules = $businessType['urgency_rules'] ?? [];

        if ($rules === []) {
            return null;
        }

        $lines = collect($rules)
            ->map(fn ($priority, $condition) => "daca {$condition} atunci priority={$priority}")
            ->implode('; ');

        return "Reguli de urgenta: {$lines}.";
    }
}
