<?php

namespace App\Services\Modes\Appointment;

use App\Models\Salon;

class AppointmentRequiredFieldsResolver
{
    public const DEFAULT_FIELDS = [
        'client_name',
        'client_phone',
        'service',
        'location',
        'date',
        'time',
    ];

    public function resolve(Salon $salon): array
    {
        $fields = self::DEFAULT_FIELDS;

        if (($salon->ai_collect_phone ?? true) === false) {
            $fields = array_values(array_diff($fields, ['client_phone']));
        }

        return $fields;
    }

    public function toolRequiredFields(Salon $salon): array
    {
        return collect($this->resolve($salon))
            ->map(fn (string $field) => match ($field) {
                'service' => 'service_id',
                'location' => 'location_id',
                default => $field,
            })
            ->values()
            ->all();
    }
}
