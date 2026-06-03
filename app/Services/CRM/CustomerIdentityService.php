<?php

namespace App\Services\CRM;

use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Salon;
use Illuminate\Support\Carbon;

class CustomerIdentityService
{
    public function normalizePhone(mixed $phone): ?string
    {
        $phone = trim((string) $phone);
        if ($phone === '') {
            return null;
        }

        $phone = preg_replace('/^whatsapp:/i', '', $phone) ?? '';
        $phone = preg_replace('/[^\d+]+/', '', $phone) ?? '';

        if (str_starts_with($phone, '00')) {
            $phone = '+'.substr($phone, 2);
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return strlen($digits) >= 8 ? $digits : null;
    }

    public function normalizeEmail(mixed $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    public function identifyFromBooking(Booking $booking): ?Customer
    {
        $booking->loadMissing('salon');
        $customer = $this->identify(
            $booking->salon,
            name: $booking->client_name,
            phone: $booking->client_phone,
            email: null,
            seenAt: $booking->created_at ?? now(),
        );

        if ($customer && $booking->customer_id !== $customer->id) {
            $booking->forceFill(['customer_id' => $customer->id])->save();
        }

        return $customer;
    }

    public function identifyFromConversation(Conversation $conversation): ?Customer
    {
        $conversation->loadMissing(['salon', 'booking.customer']);

        if ($conversation->booking?->customer) {
            $customer = $conversation->booking->customer;
            $this->touchCustomer($customer, $conversation->last_message_at ?? $conversation->created_at ?? now());

            if ($conversation->customer_id !== $customer->id) {
                $conversation->forceFill(['customer_id' => $customer->id])->save();
            }

            return $customer;
        }

        $customer = $this->identify(
            $conversation->salon,
            name: $conversation->contact_name,
            phone: $conversation->contact_phone ?: $conversation->external_contact_id,
            email: $conversation->contact_email,
            seenAt: $conversation->last_message_at ?? $conversation->created_at ?? now(),
        );

        if ($customer && $conversation->customer_id !== $customer->id) {
            $conversation->forceFill(['customer_id' => $customer->id])->save();
        }

        return $customer;
    }

    public function syncSalonHistory(Salon $salon): void
    {
        $salon->bookings()
            ->whereNull('customer_id')
            ->oldest()
            ->each(fn (Booking $booking) => $this->identifyFromBooking($booking));

        $salon->conversations()
            ->whereNull('customer_id')
            ->oldest()
            ->each(fn (Conversation $conversation) => $this->identifyFromConversation($conversation));
    }

    private function identify(Salon $salon, mixed $name, mixed $phone, mixed $email, Carbon|string|null $seenAt): ?Customer
    {
        $phoneNormalized = $this->normalizePhone($phone);
        $emailNormalized = $this->normalizeEmail($email);

        if (! $phoneNormalized && ! $emailNormalized) {
            return null;
        }

        $customer = null;

        if ($phoneNormalized) {
            $customer = $salon->customers()
                ->where('phone_normalized', $phoneNormalized)
                ->first();

            if (! $customer && $emailNormalized) {
                $customer = $salon->customers()
                    ->whereNull('phone_normalized')
                    ->where('email_normalized', $emailNormalized)
                    ->first();
            }
        } elseif ($emailNormalized) {
            $customer = $salon->customers()
                ->where('email_normalized', $emailNormalized)
                ->first();
        }

        $seen = $this->seenAt($seenAt);
        $cleanName = $this->cleanName($name);

        if (! $customer) {
            $emailBelongsToAnotherCustomer = $emailNormalized
                ? $salon->customers()
                    ->where('email_normalized', $emailNormalized)
                    ->exists()
                : false;

            return $salon->customers()->create([
                'name' => $cleanName,
                'phone' => $phoneNormalized ? trim((string) $phone) : null,
                'phone_normalized' => $phoneNormalized,
                'email' => $emailNormalized && ! $emailBelongsToAnotherCustomer ? trim((string) $email) : null,
                'email_normalized' => $emailNormalized && ! $emailBelongsToAnotherCustomer ? $emailNormalized : null,
                'first_seen_at' => $seen,
                'last_seen_at' => $seen,
                'metadata' => [],
            ]);
        }

        $updates = [];

        if (! filled($customer->name) && filled($cleanName)) {
            $updates['name'] = $cleanName;
        }

        if ($phoneNormalized && ! filled($customer->phone_normalized)) {
            $updates['phone'] = trim((string) $phone);
            $updates['phone_normalized'] = $phoneNormalized;
        }

        $emailBelongsToAnotherCustomer = $emailNormalized
            ? $salon->customers()
                ->where('email_normalized', $emailNormalized)
                ->whereKeyNot($customer->id)
                ->exists()
            : false;

        if ($emailNormalized && ! filled($customer->email_normalized) && ! $emailBelongsToAnotherCustomer) {
            $updates['email'] = trim((string) $email);
            $updates['email_normalized'] = $emailNormalized;
        }

        if (! $customer->first_seen_at || $seen->lt($customer->first_seen_at)) {
            $updates['first_seen_at'] = $seen;
        }

        if (! $customer->last_seen_at || $seen->gt($customer->last_seen_at)) {
            $updates['last_seen_at'] = $seen;
        }

        if ($updates !== []) {
            $customer->forceFill($updates)->save();
        }

        return $customer->refresh();
    }

    private function touchCustomer(Customer $customer, Carbon|string|null $seenAt): void
    {
        $seen = $this->seenAt($seenAt);
        if (! $customer->last_seen_at || $seen->gt($customer->last_seen_at)) {
            $customer->forceFill(['last_seen_at' => $seen])->save();
        }
    }

    private function seenAt(Carbon|string|null $seenAt): Carbon
    {
        if ($seenAt instanceof Carbon) {
            return $seenAt;
        }

        return filled($seenAt) ? Carbon::parse($seenAt) : now();
    }

    private function cleanName(mixed $name): ?string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        $weakNames = ['client', 'customer', 'unknown', 'necunoscut', 'vizitator', 'visitor', 'n/a'];

        return in_array(mb_strtolower($name), $weakNames, true) ? null : $name;
    }
}
