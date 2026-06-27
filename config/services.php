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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'alpha_vantage' => [
        'key' => env('ALPHA_VANTAGE_KEY'),
        'base_url' => env('ALPHA_VANTAGE_BASE_URL', 'https://www.alphavantage.co/query'),
        // EOD quotes cached for 24h to fit the free-tier 25-req/day budget.
        'cache_ttl' => (int) env('ALPHA_VANTAGE_CACHE_TTL', 86400),
        'timeout' => (int) env('ALPHA_VANTAGE_TIMEOUT', 10),
        // Minimum gap between successive HTTP requests in milliseconds.
        // Account is throttled at 1 req/sec; 1001 ms gives safe headroom.
        'throttle_ms' => (int) env('ALPHA_VANTAGE_THROTTLE_MS', 1001),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
