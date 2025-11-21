<?php

return [
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

    'tipimail' => [
        'api_user' => env('TIPIMAIL_API_USER'),
        'api_key' => env('TIPIMAIL_API_KEY'),
        'api_url' => env('TIPIMAIL_API_URL', 'https://api.tipimail.com'),
        'from' => env('TIPIMAIL_DEFAULT_FROM', 'no-reply@example.com'),
        'from_name' => env('TIPIMAIL_DEFAULT_FROM_NAME', 'WakeOnStorage'),
    ],
];
