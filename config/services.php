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
    'fastapi' => [
    'base_url' => env('FASTAPI_BASE_URL', 'http://127.0.0.1:8000'),
],

    'ai_classifier' => [
        'base_url' => env('AI_CLASSIFIER_BASE_URL', 'http://127.0.0.1:8001'),
        'endpoint' => env('AI_CLASSIFIER_ENDPOINT', '/classify'),
        'timeout'  => (int) env('AI_CLASSIFIER_TIMEOUT', 10),
    ],
    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],
    'osrm' => [
    'url' => env('OSRM_URL', 'http://localhost:5000'),
],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'twilio' => [
        'sid'   => env('TWILIO_SID'),
        'token' => env('TWILIO_TOKEN'),
        'from'  => env('TWILIO_SMS_FROM'),
    ],

];
