<?php

namespace App\Services\Modes\Appointment;

use App\Models\Booking;
use App\Models\Salon;
use App\Services\Booking\BookingCreator;

class AppointmentToolHandler
{
    public function __construct(private readonly BookingCreator $bookingCreator)
    {
    }

    public function canHandle(Salon $salon, array $functionCall): bool
    {
        return $salon->isAppointmentBased() && ($functionCall['name'] ?? null) === 'bookBooking';
    }

    public function handle(Salon $salon, array $functionCall): Booking
    {
        return $this->bookingCreator->createFromAiFunctionCall($salon, $functionCall['args'] ?? []);
    }
}
