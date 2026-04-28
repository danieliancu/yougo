<?php

namespace App\Services\Modes\Appointment;

use App\Models\Salon;

class AppointmentToolDefinitions
{
    public function __construct(
        private readonly AppointmentRequiredFieldsResolver $requiredFieldsResolver,
    ) {
    }

    public function forSalon(Salon $salon): ?array
    {
        if (! $salon->isAppointmentBased() || ! ($salon->ai_booking_enabled ?? true)) {
            return null;
        }

        return [[
            'functionDeclarations' => [
                [
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
                        'required' => $this->requiredFieldsResolver->toolRequiredFields($salon),
                    ],
                ],
                [
                    'name' => 'checkAvailability',
                    'description' => 'Verifica primele sloturi libere pentru o data, locatie si serviciu. Nu creeaza programare.',
                    'parameters' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'location_id' => ['type' => 'STRING'],
                            'service_id' => ['type' => 'STRING'],
                            'staff_id' => ['type' => 'STRING'],
                            'date' => ['type' => 'STRING'],
                        ],
                        'required' => ['location_id', 'service_id', 'date'],
                    ],
                ],
            ],
        ]];
    }
}
