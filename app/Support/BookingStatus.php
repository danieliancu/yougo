<?php

namespace App\Support;

use App\Models\Booking;
use Illuminate\Support\Carbon;

class BookingStatus
{
    public const PENDING = 'pending';
    public const CONFIRMED = 'confirmed';
    public const CANCELLED = 'cancelled';
    public const COMPLETED = 'completed';

    public static function canCustomerCancelAutomatically(Booking $booking): bool
    {
        return $booking->status === self::PENDING
            && in_array($booking->source, ['ai_assistant', 'whatsapp'], true)
            && ! self::isArchivedForDashboard($booking);
    }

    public static function isPendingCancellationAllowed(Booking $booking): bool
    {
        return self::canCustomerCancelAutomatically($booking);
    }

    public static function isClosedForWhatsappAi(Booking $booking): bool
    {
        return in_array($booking->status, [self::CONFIRMED, self::CANCELLED, self::COMPLETED], true)
            || self::isArchivedForDashboard($booking);
    }

    public static function isHistorical(Booking $booking): bool
    {
        return in_array($booking->status, [self::CANCELLED, self::COMPLETED], true)
            || self::isArchivedForDashboard($booking);
    }

    public static function isEditableByAi(Booking $booking): bool
    {
        return false;
    }

    public static function canAiEditOrReschedule(Booking $booking): bool
    {
        return false;
    }

    public static function isArchivedForDashboard(Booking $booking): bool
    {
        if (! $booking->date) {
            return false;
        }

        $timezone = $booking->salon?->timezone ?: config('app.timezone');

        return $booking->date->toDateString() < Carbon::now($timezone)->toDateString();
    }
}
