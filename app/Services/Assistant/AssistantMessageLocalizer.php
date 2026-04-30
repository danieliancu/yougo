<?php

namespace App\Services\Assistant;

use App\Models\Booking;
use App\Models\Salon;
use Illuminate\Support\Carbon;

class AssistantMessageLocalizer
{
    public function localeFor(Salon $salon): string
    {
        $languageMode = $salon->ai_language_mode;

        if (in_array($languageMode, ['ro', 'en'], true)) {
            return $languageMode;
        }

        $displayLanguage = $salon->display_language ?: config('app.locale', 'ro');

        return $displayLanguage === 'en' ? 'en' : 'ro';
    }

    public function existingBookingRequiresNewConversation(Salon $salon): string
    {
        return $this->localeFor($salon) === 'en'
            ? 'To make a new booking, please press + and start a new conversation.'
            : 'Pentru o programare nouă, apasă pe + și începe o conversație nouă.';
    }

    public function bookingConfirmation(Salon $salon, Booking $booking): string
    {
        $date = $this->dateLabel($salon, $booking->date?->format('Y-m-d') ?? '');

        return $this->localeFor($salon) === 'en'
            ? "I’ve registered your booking request for {$date} at {$booking->time}. The team will contact you to confirm it."
            : "Am înregistrat cererea de programare pentru {$date}, la ora {$booking->time}. Echipa te va contacta pentru confirmare.";
    }

    public function availabilityNoSlots(Salon $salon, string $dateLabel, ?string $preferredTime, ?string $afterTime): string
    {
        if ($this->localeFor($salon) === 'en') {
            if ($preferredTime) {
                return "The time {$preferredTime} is not available for {$dateLabel}. You can try another time, another day, or another location.";
            }

            if ($afterTime) {
                return "I could not find available slots after {$afterTime} for {$dateLabel}. You can try another time, another day, or another location.";
            }

            return "I could not find available slots for {$dateLabel}. You can try another day or another location.";
        }

        if ($preferredTime) {
            return "Ora {$preferredTime} nu este disponibilă pentru {$dateLabel}. Poți încerca altă oră, altă zi sau altă locație.";
        }

        if ($afterTime) {
            return "Nu am găsit sloturi libere după {$afterTime} pentru {$dateLabel}. Poți încerca altă oră, altă zi sau altă locație.";
        }

        return "Nu am găsit sloturi libere pentru {$dateLabel}. Poți încerca altă zi sau altă locație.";
    }

    public function availabilityPreferred(Salon $salon, string $dateLabel, string $preferredTime, array $slots): string
    {
        $slotList = implode(', ', $slots);

        if ($this->localeFor($salon) === 'en') {
            if (($slots[0] ?? null) === $preferredTime) {
                return "The time {$preferredTime} is available for {$dateLabel}. Would you like to continue with this time?";
            }

            return "The time {$preferredTime} is not available for {$dateLabel}. The closest available options are: {$slotList}. Which one would you prefer?";
        }

        if (($slots[0] ?? null) === $preferredTime) {
            return "Ora {$preferredTime} este disponibilă pentru {$dateLabel}. Vrei să continuăm cu această oră?";
        }

        return "Ora {$preferredTime} nu este disponibilă pentru {$dateLabel}. Cele mai apropiate variante disponibile sunt: {$slotList}. Ce variantă preferi?";
    }

    public function availabilityAfter(Salon $salon, string $dateLabel, string $afterTime, array $slots): string
    {
        $slotList = implode(', ', $slots);

        return $this->localeFor($salon) === 'en'
            ? "For {$dateLabel}, after {$afterTime}, I found these available slots: {$slotList}. Which one would you prefer?"
            : "Pentru {$dateLabel}, după {$afterTime}, am găsit următoarele sloturi libere: {$slotList}. Ce variantă preferi?";
    }

    public function availabilitySlots(Salon $salon, string $dateLabel, array $slots): string
    {
        $slotList = implode(', ', $slots);

        return $this->localeFor($salon) === 'en'
            ? "For {$dateLabel}, I found these available slots: {$slotList}. Which one would you prefer?"
            : "Pentru {$dateLabel}, am găsit următoarele sloturi libere: {$slotList}. Ce variantă preferi?";
    }

    public function limitMessage(Salon $salon): string
    {
        return $this->localeFor($salon) === 'en'
            ? "You've reached your plan limit for this month. Please upgrade your plan or contact the business directly."
            : 'Ai atins limita planului pentru această lună. Te rugăm să faci upgrade sau să contactezi direct businessul.';
    }

    public function geminiMissing(Salon $salon): string
    {
        if (app()->isLocal()) {
            return $this->localeFor($salon) === 'en'
                ? 'The AI assistant is not configured yet. Add GEMINI_API_KEY in .env and restart the server.'
                : 'Asistentul AI nu este configurat încă. Adaugă GEMINI_API_KEY în .env și repornește serverul.';
        }

        return $this->assistantUnavailable($salon);
    }

    public function assistantUnavailable(Salon $salon): string
    {
        return $this->localeFor($salon) === 'en'
            ? 'The AI assistant is temporarily unavailable. Please try again shortly.'
            : 'Asistentul AI nu este disponibil momentan. Te rugăm să încerci din nou în scurt timp.';
    }

    public function dateLabel(Salon $salon, string $date): string
    {
        try {
            return Carbon::createFromFormat('Y-m-d', $date)
                ->locale($this->localeFor($salon))
                ->translatedFormat('l, j F');
        } catch (\Throwable) {
            return $this->localeFor($salon) === 'en' ? 'the selected date' : 'data selectată';
        }
    }
}
