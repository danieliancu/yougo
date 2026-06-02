<?php

return [
    'account_sid' => env('TWILIO_ACCOUNT_SID'),
    'auth_token' => env('TWILIO_AUTH_TOKEN'),
    'whatsapp_from' => env('TWILIO_WHATSAPP_FROM', 'whatsapp:+14155238886'),
    'whatsapp_webhook_url' => env('TWILIO_WHATSAPP_WEBHOOK_URL', '/twilio/whatsapp/webhook'),
    'whatsapp_status_callback_url' => env('TWILIO_WHATSAPP_STATUS_CALLBACK_URL'),
    'validate_signature' => env('TWILIO_VALIDATE_SIGNATURE', true),
];
