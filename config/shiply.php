<?php

return [
    'region' => env('SHIPLY_REGION', 'palestine'),

    'base_urls' => [
        'palestine' => [
            'test' => 'https://stage.shiplylogistics.com/api/v1',
            'live' => 'https://shiplylogistics.com/api/v1',
        ],
        'jordan' => [
            'test' => 'https://stagejordan.shiplylogistics.com/api/v1',
            'live' => 'https://jordan.shiplylogistics.com/api/v1',
        ],
    ],

    'api_keys' => [
        'test' => env('SHIPLY_API_KEY_TEST', ''),
        'live' => env('SHIPLY_API_KEY_LIVE', ''),
    ],

    'webhook_path' => '/api/webhooks/shiply',

    'webhook_secret' => env('SHIPLY_WEBHOOK_SECRET', ''),

    'http_timeout' => (int) env('SHIPLY_HTTP_TIMEOUT', 30),

    'deliver_retry_minutes' => (int) env('SHIPLY_DELIVER_RETRY_MINUTES', 15),

    'parcel_status' => [
        'draft' => 1,
        'submitted' => 2,
        'on_the_way' => 3,
        'attempt_to_deliver' => 4,
        'pending' => 5,
        'delivered' => 6,
        'returned' => 7,
    ],
];
