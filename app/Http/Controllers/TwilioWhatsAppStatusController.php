<?php

namespace App\Http\Controllers;

use App\Models\ConversationMessage;
use App\Support\TwilioMessageStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Twilio\Security\RequestValidator;

class TwilioWhatsAppStatusController extends Controller
{
    private const MAX_HISTORY = 20;

    public function __invoke(Request $request)
    {
        if (! $this->hasValidSignature($request)) {
            Log::warning('Twilio WhatsApp status callback rejected invalid signature', [
                'url' => $request->fullUrl(),
                'message_sid' => $request->input('MessageSid'),
                'status' => $request->input('MessageStatus') ?: $request->input('SmsStatus'),
            ]);

            return response('Invalid signature', 403);
        }

        $messageSid = trim((string) $request->input('MessageSid', ''));
        $rawStatus = trim((string) ($request->input('MessageStatus') ?: $request->input('SmsStatus') ?: ''));
        $status = TwilioMessageStatus::normalize($rawStatus);
        $errorCode = $this->nullableString($request->input('ErrorCode'));
        $errorMessage = $this->nullableString($request->input('ErrorMessage'));

        Log::info('WhatsApp delivery status received', [
            'message_sid' => $messageSid ?: null,
            'status' => $status,
            'error_code' => $errorCode,
        ]);

        if ($messageSid === '') {
            Log::warning('WhatsApp delivery status missing MessageSid', [
                'status' => $status,
                'error_code' => $errorCode,
            ]);

            return response('', 200);
        }

        $message = ConversationMessage::query()
            ->with('conversation')
            ->where('provider', 'twilio')
            ->where('direction', 'outbound')
            ->where('provider_message_id', $messageSid)
            ->first();

        if (! $message) {
            Log::info('WhatsApp delivery status for unknown message', [
                'message_sid' => $messageSid,
                'status' => $status,
                'error_code' => $errorCode,
            ]);

            return response('', 200);
        }

        if ($this->updateDeliveryMetadata($message, $status, $rawStatus, $errorCode, $errorMessage)) {
            Log::info('WhatsApp delivery status updated', [
                'message_sid' => $messageSid,
                'status' => $status,
                'error_code' => $errorCode,
                'conversation_message_id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'salon_id' => $message->conversation?->salon_id,
            ]);
        } else {
            Log::info('WhatsApp delivery status duplicate ignored', [
                'message_sid' => $messageSid,
                'status' => $status,
                'error_code' => $errorCode,
                'conversation_message_id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'salon_id' => $message->conversation?->salon_id,
            ]);
        }

        return response('', 200);
    }

    private function hasValidSignature(Request $request): bool
    {
        if (! (bool) config('twilio.validate_signature', true)) {
            return true;
        }

        $authToken = config('twilio.auth_token');
        if (! filled($authToken)) {
            return false;
        }

        $signature = (string) $request->header('X-Twilio-Signature', '');
        if ($signature === '') {
            return false;
        }

        return (new RequestValidator($authToken))->validate(
            $signature,
            $request->fullUrl(),
            $request->post(),
        );
    }

    private function updateDeliveryMetadata(ConversationMessage $message, string $status, string $rawStatus, ?string $errorCode, ?string $errorMessage): bool
    {
        $metadata = $message->metadata ?? [];
        $delivery = is_array($metadata['delivery'] ?? null) ? $metadata['delivery'] : [];
        $history = collect(is_array($delivery['history'] ?? null) ? $delivery['history'] : []);
        $now = now()->toISOString();
        $event = [
            'status' => $status,
            'at' => $now,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
        ];

        $last = $history->last();
        $isDuplicate = is_array($last)
            && ($last['status'] ?? null) === $status
            && ($last['error_code'] ?? null) === $errorCode
            && ($last['error_message'] ?? null) === $errorMessage;

        if (! $isDuplicate) {
            $history->push($event);
        }

        $delivery = [
            ...$delivery,
            'status' => $status,
            'raw_status' => $rawStatus ?: null,
            'updated_at' => $now,
            'history' => $history->take(-self::MAX_HISTORY)->values()->all(),
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
        ];

        $metadata = [
            ...$metadata,
            'delivery_status' => $status,
            'delivery' => $delivery,
        ];

        if (($metadata['status'] ?? null) !== 'failed') {
            $metadata['status'] = $status;
        }

        $message->update(['metadata' => $metadata]);

        return ! $isDuplicate;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
