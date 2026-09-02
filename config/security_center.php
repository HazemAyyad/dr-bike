<?php

return [
    'web_token' => env('SECURITY_CENTER_WEB_TOKEN', 'hazem'),
    'sample_seconds' => (int) env('SECURITY_CENTER_SAMPLE_SECONDS', 20),
    'block_cache_seconds' => (int) env('SECURITY_CENTER_BLOCK_CACHE_SECONDS', 30),
    'geolocation' => [
        'enabled' => (bool) env('SECURITY_CENTER_GEOLOCATION_ENABLED', true),
        'endpoint' => env('SECURITY_CENTER_GEOLOCATION_ENDPOINT', 'https://ipwho.is'),
        'refresh_days' => (int) env('SECURITY_CENTER_GEOLOCATION_REFRESH_DAYS', 30),
    ],
];
