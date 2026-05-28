<?php

return [
    'key' => env('STRIPE_KEY'),
    'secret' => env('STRIPE_SECRET'),
    'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    'prices' => [
        'website_chat' => env('STRIPE_PRICE_WEBSITE_CHAT'),
        'chat_whatsapp' => env('STRIPE_PRICE_CHAT_WHATSAPP'),
        'voice_starter' => env('STRIPE_PRICE_VOICE_STARTER'),
        'voice_growth' => env('STRIPE_PRICE_VOICE_GROWTH'),
        'voice_pro' => env('STRIPE_PRICE_VOICE_PRO'),
    ],
];
