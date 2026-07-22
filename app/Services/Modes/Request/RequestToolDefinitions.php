<?php

namespace App\Services\Modes\Request;

use App\Enums\RequestPriority;
use App\Enums\RequestType;
use App\Models\Salon;

/**
 * Mirrors AppointmentToolDefinitions for the `request` capability. The Gemini function
 * schema only declares the generic, cross-industry fields (type/title/description/
 * priority/preferred date-window/location/service/contact); anything industry-specific
 * the AI collects (per RequestPromptContextBuilder's `collected_fields`) goes into the
 * free-form `structured_data` JSON string property, validated server-side by
 * RequestToolHandler — never trusted as-is.
 */
class RequestToolDefinitions
{
    public function forSalon(Salon $salon): ?array
    {
        if (! $salon->hasCapability(Salon::CAPABILITY_REQUEST)) {
            return null;
        }

        return [[
            'functionDeclarations' => [
                [
                    'name' => 'createRequest',
                    'description' => 'Creeaza o solicitare (nu o programare) in baza de date, cand clientul cere o oferta, o interventie, un callback, un diagnostic sau informatii pe care echipa trebuie sa le proceseze manual.',
                    'parameters' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'type' => ['type' => 'STRING', 'enum' => RequestType::values()],
                            'title' => ['type' => 'STRING'],
                            'description' => ['type' => 'STRING'],
                            'priority' => ['type' => 'STRING', 'enum' => RequestPriority::values()],
                            'preferred_date' => ['type' => 'STRING'],
                            'preferred_window' => ['type' => 'STRING'],
                            'location_id' => ['type' => 'STRING'],
                            'service_id' => ['type' => 'STRING'],
                            'client_name' => ['type' => 'STRING'],
                            'client_phone' => ['type' => 'STRING'],
                            'structured_data' => ['type' => 'STRING'],
                        ],
                        'required' => $this->requiredFields($salon),
                    ],
                ],
            ],
        ]];
    }

    /**
     * @return list<string>
     */
    private function requiredFields(Salon $salon): array
    {
        $fields = ['description', 'client_name', 'client_phone'];

        if (($salon->ai_collect_phone ?? true) === false) {
            $fields = array_values(array_diff($fields, ['client_phone']));
        }

        return $fields;
    }
}
