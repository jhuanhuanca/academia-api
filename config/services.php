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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'luna' => [
        'base_url' => env('LUNA_BASE_URL', 'http://127.0.0.1:8001'),
        'api_key' => env('LUNA_API_KEY', 'dev-motor-ia-key-change-me'),
        'timeout' => (float) env('LUNA_TIMEOUT', 20),
    ],

    'evolution' => [
        'base_url' => env('EVOLUTION_BASE_URL', 'http://127.0.0.1:8080'),
        'api_key' => env('EVOLUTION_API_KEY', 'marketluna-evolution-key-change-me'),
        'timeout' => (float) env('EVOLUTION_TIMEOUT', 30),
        'default_instance' => env('EVOLUTION_INSTANCE', 'academia-ventas'),
        'webhook_url' => env('EVOLUTION_WEBHOOK_URL', 'http://host.docker.internal:8000/api/webhooks/evolution'),
    ],

    'frontend_url' => env('FRONTEND_URL', 'http://127.0.0.1:5173'),
];
