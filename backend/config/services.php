<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'google' => [
        // Client-side Google Identity Services flow — only the Client ID is
        // used (no secret needed); the backend verifies the ID token's
        // audience against this value.
        'client_id' => env('GOOGLE_CLIENT_ID'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

'semaphore' => [
        // Delivery driver: 'semaphore' hits the real API (production);
        // 'log' writes to storage/logs/sms.log instead of sending (dev/test —
        // Semaphore has no sandbox, every real call costs credits). Defaults to
        // log outside production so the whole flow works offline; an explicit
        // SMS_DRIVER always wins.
        // NOTE: app()->environment() is NOT available here — the 'env'
        // binding is created only after config files load (LoadConfiguration
        // detects the environment at the very end), so the default derives
        // from APP_ENV directly, exactly like config/app.php does.
        'driver' => env('SMS_DRIVER', env('APP_ENV', 'production') === 'production' ? 'semaphore' : 'log'),
        // Leave empty to disable SMS delivery entirely (the portal then
        // hides the SMS option and nothing ever calls Semaphore).
        'api_key' => env('SEMAPHORE_API_KEY'),
        'sender_name' => env('SEMAPHORE_SENDER_NAME', 'GW-SYSTEM'),
        // OTP-dedicated route: 2 credits per 160-char SMS, messages routed to
        // telco OTP traffic even under high volume. Accepts a `code` param to
        // send our own code and a `{otp}` placeholder in the message.
        'otp_endpoint' => env('SEMAPHORE_OTP_ENDPOINT', 'https://api.semaphore.co/api/v4/otp'),
    ],

    'paymongo' => [
        'secret_key' => env('PAYMONGO_SECRET_KEY'),
        'public_key' => env('PAYMONGO_PUBLIC_KEY'),
        'webhook_secret' => env('PAYMONGO_WEBHOOK_SECRET'),
        'livemode' => filter_var(env('PAYMONGO_LIVEMODE', false), FILTER_VALIDATE_BOOLEAN),
        // QR Ph payment-method expiry (seconds, 60–9000 per PayMongo docs). Sent to the
        // customer portal as the checkout deadline — the countdown is always driven by
        // this backend value, never a frontend constant.
        'qr_expiry_seconds' => (int) env('PAYMONGO_QR_EXPIRY_SECONDS', 600),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
