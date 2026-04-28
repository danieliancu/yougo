<?php

namespace App\Services\Assistant;

use App\Models\Salon;
use App\Services\Modes\Appointment\AppointmentPromptContextBuilder;
use App\Services\Modes\Appointment\AppointmentToolDefinitions;
use App\Support\BusinessTaxonomy;

class GeminiPayloadBuilder
{
    public function __construct(
        private readonly AppointmentPromptContextBuilder $appointmentPromptContextBuilder,
        private readonly AppointmentToolDefinitions $appointmentToolDefinitions,
    ) {
    }

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

        $tools = $this->appointmentToolDefinitions->forSalon($salon);
        if ($tools) {
            $payload['tools'] = $tools;
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
            $this->aiBusinessContext($salon),
            "Context produs: modul curent este {$this->businessMode($salon)}. Pentru moment aplicatia activeaza doar fluxul appointment.",
            $this->modeInstructions($salon),
            $this->ownerInstructions($salon),
        ])->filter()->implode(' ');
    }

    private function businessDetails(Salon $salon): string
    {
        return collect([
            "nume business: {$salon->name}",
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

    private function modeInstructions(Salon $salon): string
    {
        if (! $salon->isAppointmentBased()) {
            return 'Modul curent nu este appointment. Nu crea programari si nu folosi functia bookBooking; raspunde doar informational folosind datele configurate.';
        }

        return $this->appointmentPromptContextBuilder->build($salon);
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
