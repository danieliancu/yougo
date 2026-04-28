<?php

namespace App\Services\Modes\Appointment;

use App\Models\Booking;
use App\Models\Salon;
use App\Services\Booking\AvailabilitySlotFinder;
use App\Services\Booking\BookingCreator;

class AppointmentToolHandler
{
    public function __construct(
        private readonly BookingCreator $bookingCreator,
        private readonly AvailabilitySlotFinder $availabilitySlotFinder,
    )
    {
    }

    public function canHandle(Salon $salon, array $functionCall): bool
    {
        return $salon->isAppointmentBased() && in_array(($functionCall['name'] ?? null), ['bookBooking', 'checkAvailability'], true);
    }

    public function isBookingCall(array $functionCall): bool
    {
        return ($functionCall['name'] ?? null) === 'bookBooking';
    }

    public function isAvailabilityCall(array $functionCall): bool
    {
        return ($functionCall['name'] ?? null) === 'checkAvailability';
    }

    public function handle(Salon $salon, array $functionCall): Booking
    {
        return $this->bookingCreator->createFromAiFunctionCall($salon, $functionCall['args'] ?? [], 'ai_assistant');
    }

    public function availabilityMessage(Salon $salon, array $functionCall): string
    {
        $args = $functionCall['args'] ?? [];
        $slots = $this->availabilitySlotFinder->find(
            $salon,
            (int) ($args['location_id'] ?? 0),
            (int) ($args['service_id'] ?? 0),
            (string) ($args['date'] ?? ''),
            isset($args['staff_id']) ? (int) $args['staff_id'] : null,
        );

        if (count($slots) === 0) {
            return 'Nu am gasit sloturi libere pentru data selectata. Poti incerca alta zi sau alta locatie.';
        }

        return 'Am gasit urmatoarele sloturi libere: '.implode(', ', $slots).'. Ce varianta preferi?';
    }
}
