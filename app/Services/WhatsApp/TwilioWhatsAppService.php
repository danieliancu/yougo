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
                $this->messageOptions($from, $body),
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

    public function normalizeInternationalPhoneNumber(string $number, bool $allowWhatsappPrefix = false): string
    {
        $number = trim($number);

        if ($allowWhatsappPrefix && str_starts_with($number, 'whatsapp:')) {
            $number = substr($number, strlen('whatsapp:'));
        }

        $number = preg_replace('/[^\d+]/', '', $number) ?? '';

        if (str_starts_with($number, '00')) {
            $number = '+'.substr($number, 2);
        }

        if (! str_starts_with($number, '+')) {
            return '';
        }

        return $number;
    }

    public function normalizeWhatsappSender(string $number): string
    {
        $phoneNumber = $this->normalizeInternationalPhoneNumber($number, allowWhatsappPrefix: true);

        return $phoneNumber === '' ? '' : 'whatsapp:'.$phoneNumber;
    }

    public function normalizePhoneNumber(string $number): string
    {
        $number = trim($number);
        $number = preg_replace('/[^\d+]/', '', $number) ?? '';

        if (str_starts_with($number, '00')) {
            $number = '+'.substr($number, 2);
        }

        if ($number !== '' && ! str_starts_with($number, '+')) {
            $number = '+'.$number;
        }

        return $number;
    }

    protected function messageOptions(string $from, string $body): array
    {
        $options = [
            'from' => $this->normalizeAddress($from),
            'body' => $body,
        ];

        $statusCallback = $this->statusCallbackUrl();
        if ($statusCallback) {
            $options['statusCallback'] = $statusCallback;
        }

        return $options;
    }

    protected function statusCallbackUrl(): ?string
    {
        $configured = trim((string) config('twilio.whatsapp_status_callback_url', ''));
        if ($configured !== '') {
            return $configured;
        }

        $appUrl = trim((string) config('app.url', ''));
        if ($appUrl === '' || $this->isLocalUrl($appUrl)) {
            return null;
        }

        return route('twilio.whatsapp.status');
    }

    private function safeAddress(string $number): string
    {
        $address = $this->normalizeAddress($number);

        return strlen($address) <= 8
            ? $address
            : substr($address, 0, 12).'...'.substr($address, -4);
    }

    private function isLocalUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }
}
