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

    'amazon_sp_api' => [
        'client_id' => env('AMAZON_SP_API_CLIENT_ID'),
        'client_secret' => env('AMAZON_SP_API_CLIENT_SECRET'),
        'refresh_token' => env('AMAZON_SP_API_REFRESH_TOKEN'),
        'application_id' => env('AMAZON_SP_API_APPLICATION_ID'),
        'marketplace_ids' => explode(',', env('AMAZON_SP_API_MARKETPLACE_IDS')), // Comma-separated list
        'endpoint' => env('AMAZON_SP_API_ENDPOINT', 'EU'), // Default to EU

    ],

    'amazon_ads' => [
        'client_id' => env('AMAZON_ADS_CLIENT_ID'),
        'client_secret' => env('AMAZON_ADS_CLIENT_SECRET'),
        'refresh_token' => env('AMAZON_ADS_REFRESH_TOKEN'),
        'base_url' => env('AMAZON_ADS_BASE_URL', 'https://advertising-api-eu.amazon.com'),
    ],

    'sqs' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        'queue_url' => env('SQS_QUEUE_URL'),
    ],

];
