<?php

return [
    'catalog_id' => env('META_CATALOG_ID'),
    'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
    'api_version' => env('WHATSAPP_API_VERSION', 'v25.0'),
    'timeout' => (int) env('WHATSAPP_HTTP_TIMEOUT', 20),
    'public_url' => env('META_CATALOG_PUBLIC_URL', env('APP_URL')),
];
