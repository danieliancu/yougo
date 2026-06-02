<?php

namespace App\Http\Controllers;

use App\Mail\WhatsappSetupRequestMail;
use App\Models\Conversation;
use App\Models\Salon;
use App\Models\WhatsappIntegration;
use App\Services\WhatsApp\TwilioWhatsAppService;
use App\Services\WhatsApp\WhatsAppConversationService;
use App\Support\YouGoServices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class WhatsappSettingsController extends Controller
{
    public function requestActivation(Request $request, TwilioWhatsAppService $twilio): JsonResponse
    {
        $salon = $this->salon($request);
        $this->ensureWhatsappPlan($salon);

        $data = $request->validate([
            'requested_number' => ['required', 'string', 'max:40', 'regex:/^\+?[0-9\s().-]+$/'],
        ]);

        $requestedNumber = $twilio->normalizeInternationalPhoneNumber($data['requested_number']);

        if (! preg_match('/^\+\d{8,15}$/', $requestedNumber)) {
            throw ValidationException::withMessages([
                'requested_number' => __('Please enter the number in international format, for example +447...'),
            ]);
        }

        $integration = $salon->whatsappIntegration()->updateOrCreate(
            ['salon_id' => $salon->id],
            [
                'provider' => 'twilio',
                'requested_number' => $requestedNumber,
                'status' => WhatsappIntegration::STATUS_REQUESTED,
                'requested_at' => now(),
            ],
        );

        return response()->json([
            'integration' => $this->serializeIntegration($integration->refresh()),
            'message' => 'activation_requested',
        ]);
    }

    public function toggle(Request $request): JsonResponse
    {
        $salon = $this->salon($request);
        $this->ensureWhatsappPlan($salon);

        $data = $request->validate([
            'ai_enabled' => ['required', 'boolean'],
        ]);

        $integration = $salon->whatsappIntegration;
        abort_unless($integration, 404);
        abort_unless($integration->status === WhatsappIntegration::STATUS_ACTIVE, 422, 'WhatsApp is not active yet.');

        $integration->update(['ai_enabled' => (bool) $data['ai_enabled']]);

        return response()->json([
            'integration' => $this->serializeIntegration($integration->refresh()),
        ]);
    }

    public function setupRequest(Request $request, TwilioWhatsAppService $twilio): JsonResponse
    {
        $salon = $this->salon($request);
        $this->ensureWhatsappPlan($salon);

        $data = $request->validate([
            'business_name' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['required', 'string', 'max:255'],
            'contact_email' => ['required', 'email', 'max:255'],
            'contact_phone' => ['required', 'string', 'max:80'],
            'requested_whatsapp_number' => ['required', 'string', 'max:80', 'regex:/^\+?[0-9\s().-]+$/'],
            'whatsapp_display_name' => ['nullable', 'string', 'max:255'],
            'website_or_social_link' => ['nullable', 'string', 'max:500'],
            'has_meta_business_account' => ['nullable', 'in:yes,no,not_sure'],
            'number_currently_used_on_whatsapp_app' => ['nullable', 'in:yes,no,not_sure'],
            'can_receive_sms_or_call' => ['nullable', 'in:yes,no'],
            'preferred_meeting_type' => ['required', 'in:video_call,phone_call'],
            'preferred_availability' => ['required', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'username' => ['prohibited'],
            'password' => ['prohibited'],
            'facebook_password' => ['prohibited'],
            'meta_password' => ['prohibited'],
            'two_factor_code' => ['prohibited'],
            '2fa_code' => ['prohibited'],
        ]);

        $requestedWhatsappNumber = $twilio->normalizeInternationalPhoneNumber($data['requested_whatsapp_number']);

        if (! preg_match('/^\+\d{8,15}$/', $requestedWhatsappNumber)) {
            throw ValidationException::withMessages([
                'requested_whatsapp_number' => __('Please enter the number in international format, for example +447...'),
            ]);
        }

        $form = [
            'business_name' => $data['business_name'] ?? $salon->name,
            'contact_person' => $data['contact_person'],
            'contact_email' => $data['contact_email'],
            'contact_phone' => $data['contact_phone'],
            'requested_whatsapp_number' => $requestedWhatsappNumber,
            'whatsapp_display_name' => $data['whatsapp_display_name'] ?? '',
            'website_or_social_link' => $data['website_or_social_link'] ?? '',
            'has_meta_business_account' => $data['has_meta_business_account'] ?? '',
            'number_currently_used_on_whatsapp_app' => $data['number_currently_used_on_whatsapp_app'] ?? '',
            'can_receive_sms_or_call' => $data['can_receive_sms_or_call'] ?? '',
            'preferred_meeting_type' => $data['preferred_meeting_type'],
            'preferred_availability' => $data['preferred_availability'],
            'notes' => $data['notes'] ?? '',
        ];

        $integration = $salon->whatsappIntegration()->firstOrCreate(
            ['salon_id' => $salon->id],
            [
                'provider' => 'twilio',
                'requested_number' => $form['requested_whatsapp_number'],
                'status' => WhatsappIntegration::STATUS_REQUESTED,
                'requested_at' => now(),
            ],
        );

        $metadata = $integration->metadata ?? [];
        $integration->forceFill([
            'requested_number' => $integration->requested_number ?: $form['requested_whatsapp_number'],
            'status' => $integration->status === WhatsappIntegration::STATUS_ACTIVE ? $integration->status : WhatsappIntegration::STATUS_REQUESTED,
            'requested_at' => $integration->requested_at ?: now(),
            'metadata' => [
                ...$metadata,
                'latest_setup_request' => [
                    ...$form,
                    'submitted_at' => now()->toIso8601String(),
                    'submitted_by_user_id' => $request->user()?->id,
                    'submitted_by_email' => $request->user()?->email,
                ],
            ],
        ])->save();

        Mail::to(config('mail.whatsapp_setup_request_to'))->send(
            new WhatsappSetupRequestMail(
                salon: $salon,
                user: $request->user(),
                form: $form,
                integration: $integration->refresh(),
            ),
        );

        return response()->json([
            'ok' => true,
            'message' => 'whatsapp_setup_request_sent',
        ]);
    }

    public function testMessage(Request $request, TwilioWhatsAppService $twilio, WhatsAppConversationService $conversations): JsonResponse
    {
        $salon = $this->salon($request);
        $this->ensureWhatsappPlan($salon);

        $data = $request->validate([
            'to' => ['required', 'string', 'max:40', 'regex:/^\+?[0-9\s().-]+$/'],
            'message' => ['required', 'string', 'max:1000'],
        ]);

        $integration = $salon->whatsappIntegration;
        abort_unless($integration, 404);
        abort_unless($integration->status === WhatsappIntegration::STATUS_ACTIVE, 422, 'WhatsApp is not active yet.');
        abort_unless(filled($integration->twilio_sender), 422, 'WhatsApp number is not configured.');

        $to = $twilio->normalizeAddress($data['to']);
        $result = $twilio->sendMessage($integration->twilio_sender, $to, $data['message']);

        $conversation = $this->resolveTestConversation($salon, $integration->twilio_sender, $to);
        $message = $conversations->saveOutbound($conversation, $data['message'], $result);

        return response()->json([
            'ok' => true,
            'message_id' => $message->id,
            'provider' => $result,
        ]);
    }

    private function salon(Request $request): Salon
    {
        $salon = $request->user()?->salon;

        abort_unless($salon, 403);

        return $salon;
    }

    private function ensureWhatsappPlan(Salon $salon): void
    {
        abort_unless(YouGoServices::planHasWhatsappAi($salon->plan), 403, 'WhatsApp AI requires a compatible plan.');
    }

    private function resolveTestConversation(Salon $salon, string $from, string $to): Conversation
    {
        return $salon->conversations()->firstOrCreate(
            [
                'channel' => 'whatsapp',
                'provider' => 'twilio',
                'external_contact_id' => $to,
                'external_sender' => $from,
            ],
            [
                'contact_phone' => $to,
                'status' => 'open',
                'intent' => 'inquiry',
                'summary' => 'Mesaj test WhatsApp trimis prin YouGo.',
                'last_message_at' => now(),
            ],
        );
    }

    private function serializeIntegration(WhatsappIntegration $integration): array
    {
        return [
            'id' => $integration->id,
            'provider' => $integration->provider,
            'requested_number' => $integration->requested_number,
            'twilio_sender' => $integration->twilio_sender,
            'display_number' => $integration->display_number,
            'status' => $integration->status,
            'ai_enabled' => $integration->ai_enabled,
            'last_verified_at' => $integration->last_verified_at?->toIso8601String(),
            'activated_at' => $integration->activated_at?->toIso8601String(),
            'requested_at' => $integration->requested_at?->toIso8601String(),
        ];
    }
}
