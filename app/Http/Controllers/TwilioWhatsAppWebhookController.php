<?php

namespace App\Http\Controllers;

use App\Models\WhatsappIntegration;
use App\Services\WhatsApp\WhatsAppAiReplyService;
use App\Services\WhatsApp\WhatsAppConversationService;
use App\Support\YouGoServices;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Twilio\Security\RequestValidator;

class TwilioWhatsAppWebhookController extends Controller
{
    public function __invoke(Request $request, WhatsAppConversationService $conversations, WhatsAppAiReplyService $aiReplies)
    {
        if (! $this->hasValidSignature($request)) {
            Log::warning('Twilio WhatsApp webhook rejected invalid signature', [
                'url' => $request->fullUrl(),
                'to' => $request->input('To'),
                'message_sid' => $request->input('MessageSid'),
            ]);

            return response('Invalid signature', 403);
        }

        $payload = $request->all();
        $to = (string) ($payload['To'] ?? '');
        $from = (string) ($payload['From'] ?? '');
        $body = trim((string) ($payload['Body'] ?? ''));
        $messageSid = (string) ($payload['MessageSid'] ?? '');
        $numMedia = (int) ($payload['NumMedia'] ?? 0);

        if ($from !== '' && $to !== '' && $from === $to) {
            Log::info('Twilio WhatsApp webhook ignored message from business sender', [
                'to' => $to,
                'message_sid' => $messageSid,
            ]);

            return $this->twiml();
        }

        $integration = WhatsappIntegration::query()
            ->with('salon')
            ->where('provider', 'twilio')
            ->where('twilio_sender', $to)
            ->where('status', WhatsappIntegration::STATUS_ACTIVE)
            ->first();

        if (! $integration || ! $integration->salon) {
            Log::warning('Twilio WhatsApp webhook received for unknown sender', [
                'to' => $to,
                'message_sid' => $messageSid,
            ]);

            return $this->twiml();
        }

        if ($messageSid !== '' && $conversations->hasProviderMessage($messageSid)) {
            return $this->twiml();
        }

        $salon = $integration->salon;

        if (! YouGoServices::planHasWhatsappAi($salon->plan)) {
            Log::info('Twilio WhatsApp webhook ignored for salon without WhatsApp entitlement', [
                'salon_id' => $salon->id,
                'plan' => $salon->plan,
                'message_sid' => $messageSid,
            ]);

            return $this->twiml();
        }

        $inboundMessage = $conversations->saveInbound($salon, $payload);
        $conversation = $inboundMessage->conversation;

        if (! $integration->ai_enabled) {
            return $this->twiml();
        }

        if ($body === '') {
            if ($numMedia > 0 && $conversation) {
                $aiReplies->sendUnsupportedTextMessage($salon, $integration, $conversation);
            }

            return $this->twiml();
        }

        $aiReplies->handleInbound($salon, $integration, $inboundMessage, $payload);

        return $this->twiml();
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

    private function twiml()
    {
        return response('<Response></Response>', 200)->header('Content-Type', 'text/xml');
    }
}
