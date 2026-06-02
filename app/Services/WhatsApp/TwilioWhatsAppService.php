<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use Twilio\Rest\Client;

class TwilioWhatsAppService
{
    public function sendMessage(string $from, string $to, string $body): array
    {
        $accountSid = config('twilio.account_sid');
        $authToken = config('twilio.auth_token');

        if (! filled($accountSid) || ! filled($authToken)) {
            throw new RuntimeException('Twilio WhatsApp is not configured.');
        }

        try {
            $message = (new Client($accountSid, $authToken))->messages->create(
                $this->normalizeAddress($to),
                [
                    'from' => $this->normalizeAddress($from),
                    'body' => $body,
                ],
            );

            return [
                'sid' => $message->sid,
                'status' => $message->status,
                'to' => $message->to,
                'from' => $message->from,
            ];
        } catch (Throwable $exception) {
            Log::warning('Twilio WhatsApp send failed', [
                'exception' => $exception::class,
                'code' => $exception->getCode() ?: null,
                'message' => $exception->getMessage(),
                'from' => $this->safeAddress($from),
                'to' => $this->safeAddress($to),
            ]);

            throw new RuntimeException('WhatsApp message could not be sent.', (int) $exception->getCode(), $exception);
        }
    }

    public function normalizeAddress(string $number): string
    {
        $number = trim($number);

        if (str_starts_with($number, 'whatsapp:')) {
            return $number;
        }

        return 'whatsapp:'.$this->normalizePhoneNumber($number);
    }

    public function normalizePhoneNumber(string $number): string
    {
        $number = trim($number);
        $number = preg_replace('/[^\d+]/', '', $number) ?? '';

        if ($number !== '' && ! str_starts_with($number, '+')) {
            $number = '+'.$number;
        }

        return $number;
    }

    private function safeAddress(string $number): string
    {
        $address = $this->normalizeAddress($number);

        return strlen($address) <= 8
            ? $address
            : substr($address, 0, 12).'...'.substr($address, -4);
    }
}
