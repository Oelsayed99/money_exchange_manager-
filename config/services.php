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

    /*
     * Reference exchange rates for the dashboard.
     *
     * Display only. Nothing derived from these ever reaches the ledger — see
     * App\Domain\Rates\ReferenceRates. Set RATES_ENABLED=false to make no outbound
     * request at all.
     */
    'rates' => [
        'enabled' => env('RATES_ENABLED', true),
        'url' => env('RATES_URL', 'https://open.er-api.com/v6/latest'),
        'base' => env('RATES_BASE', 'USD'),
        // The free feed publishes daily; an hour is often enough to catch the change
        // and rare enough to be a good neighbour.
        'ttl' => env('RATES_TTL', 3600),
        'timeout' => env('RATES_TIMEOUT', 6),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
