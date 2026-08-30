<?php

use App\Domain\Auth\GoogleProvider;

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

    /*
     * Signing in through somebody else.
     *
     * A provider with no client id simply does not appear on the sign-in screen — see
     * SocialAuthController::available() — so this file is the whole of what turns
     * Google on. Apple is listed the day its credentials exist and an AppleProvider is
     * written; nothing else has to change for it.
     */
    'social_providers' => [
        GoogleProvider::class,
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),

        // Left empty, the callback route is used, which is right in every environment
        // where APP_URL is right. Set it only when a proxy makes them differ.
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

];
