<?php

namespace App\Support;

use App\Models\Salon;

/**
 * Single source of truth for "which dashboard modules does this salon's active capability
 * set unlock" (Task 4 §11) — the table from the task spec, expressed once here instead of
 * scattered `if ($salon->hasCapability(...))` checks across Dashboard/Index.tsx.
 *
 * Reservation columns are placeholders only — nothing in this registry can ever return
 * true for a reservation-only module (requests-analog for reservation, resources,
 * check-in/out...), since Reservation cannot be activated yet.
 */
class DashboardModuleRegistry
{
    /**
     * @return array<string, bool>
     */
    public static function forSalon(Salon $salon): array
    {
        $hasAppointment = $salon->hasCapability(Salon::CAPABILITY_APPOINTMENT);
        $hasRequest = $salon->hasCapability(Salon::CAPABILITY_REQUEST);

        return [
            'conversations' => $hasAppointment || $hasRequest,
            'customers' => $hasAppointment || $hasRequest,
            'appointments' => $hasAppointment,
            'requests' => $hasRequest,
            'calendar' => $hasAppointment,
            'staff' => $hasAppointment || $hasRequest,
            'services' => $hasAppointment,
            // Recognized for the future (Task 4 §11), never true today.
            'reservations' => false,
            'resources' => false,
        ];
    }
}
