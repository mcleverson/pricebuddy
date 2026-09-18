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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'pushover' => [
        'token' => env('PUSHOVER_APP_TOKEN'),
    ],

    'hermes' => [
        'url' => env('HERMES_URL', 'http://hermes:8000'),
        // Must exceed HERMES_RUN_TIMEOUT_SECONDS (the agent's own self-imposed
        // deadline, see hermes/src/config.py): Hermes always stops itself and
        // replies within that budget, but if this timeout matches it exactly,
        // whichever clock fires first wins the race — the client gives up on
        // the exact same tick Hermes tries to write its response, breaking
        // the pipe and making the run look failed even though it finished.
        // The margin below covers report generation + the final candidate
        // ingest call, which happen after the agent's own deadline.
        'timeout' => (int) env('HERMES_RUN_TIMEOUT_SECONDS', 600) + 120,
    ],

    'pricebuddy' => [
        // Same values Hermes uses to call back into this app (see docker-compose.yml);
        // reused here so API-driven discovery (buddy:agent-strategy-run for
        // access_mode=Api stores) ingests candidates through the exact same
        // authenticated endpoint and ownership, without a separate HTTP hop.
        'api_base_url' => env('PRICEBUDDY_API_BASE_URL', 'http://app/api'),
        'api_token' => env('PRICEBUDDY_API_TOKEN'),
    ],

];
