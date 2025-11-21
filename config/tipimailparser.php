<?php

return [
    'api_user' => env('TIPIMAIL_API_USER'),
    'api_key' => env('TIPIMAIL_API_KEY'),
    'api_url' => env('TIPIMAIL_API_URL', 'https://api.tipimail.com'),
    'default_sender' => [
        'address' => env('TIPIMAIL_DEFAULT_FROM', 'no-reply@example.com'),
        'name' => env('TIPIMAIL_DEFAULT_FROM_NAME', 'WakeOnStorage'),
    ],
    'webhook' => [
        'signature_header' => 'X-TipiMail-Signature',
        'timestamp_header' => 'X-TipiMail-Timestamp',
    ],
];
