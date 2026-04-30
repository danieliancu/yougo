<?php

namespace App\Services\Modes\Appointment;

use App\Models\Booking;
use App\Models\Salon;
use App\Services\Assistant\AssistantMessageLocalizer;
use App\Services\Booking\AvailabilitySlotFinder;
use App\Services\Booking\BookingCreator;

class AppointmentToolHandler
{
    public function __construct(
        private readonly BookingCreator $bookingCreator,
        private readonly AvailabilitySlotFinder $availabilitySlotFinder,
        private readonly AssistantMessageLocalizer $messageLocalizer,
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

    public function handle(Salon $salon, array $functionCall, bool $billUsage = true): Booking
    {
        return $this->bookingCreator->createFromAiFunctionCall($salon, $functionCall['args'] ?? [], 'ai_assistant', $billUsage);
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
            preferredTime: isset($args['preferred_time']) ? (string) $args['preferred_time'] : null,
            afterTime: isset($args['after_time']) ? (string) $args['after_time'] : null,
        );

        $dateLabel = $this->messageLocalizer->dateLabel($salon, (string) ($args['date'] ?? ''));
        $preferredTime = $this->normalizedTime(isset($args['preferred_time']) ? (string) $args['preferred_time'] : null);
        $afterTime = $this->normalizedTime(isset($args['after_time']) ? (string) $args['after_time'] : null);

        if (count($slots) === 0) {
            return $this->messageLocalizer->availabilityNoSlots($salon, $dateLabel, $preferredTime, $afterTime);
        }

        if ($preferredTime) {
            return $this->messageLocalizer->availabilityPreferred($salon, $dateLabel, $preferredTime, $slots);
        }

        if ($afterTime) {
            return $this->messageLocalizer->availabilityAfter($salon, $dateLabel, $afterTime, $slots);
        }

        return $this->messageLocalizer->availabilitySlots($salon, $dateLabel, $slots);
    }

    private function normalizedTime(?string $time): ?string
    {
        if ($time === null) {
            return null;
        }

        $time = trim($time);

        if (preg_match('/^(\d{1,2})(?::|\.)(\d{2})$/', $time, $matches)) {
            $hour = (int) $matches[1];
            $minute = (int) $matches[2];
        } elseif (preg_match('/^\d{1,2}$/', $time)) {
            $hour = (int) $time;
            $minute = 0;
        } else {
            return null;
        }

        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }
}
