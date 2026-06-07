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

    'resend' => [
        'key' => env('RESEND_API_KEY'),
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

    'youtube' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
        'default_privacy' => env('YOUTUBE_DEFAULT_PRIVACY', 'public'),
        'publish_mode' => env('YOUTUBE_PUBLISH_MODE', 'golden_hour'),
        'publish_timezone' => env('YOUTUBE_PUBLISH_TIMEZONE', env('APP_TIMEZONE', 'Asia/Jakarta')),
        'golden_fixed_window_minutes' => (int) env('YOUTUBE_GOLDEN_FIXED_WINDOW_MINUTES', 30),
        'min_schedule_lead_minutes' => (int) env('YOUTUBE_MIN_SCHEDULE_LEAD_MINUTES', 15),
    ],

];
