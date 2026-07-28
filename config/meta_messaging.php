<?php

return [
    'api_version' => env('META_API_VERSION', env('WHATSAPP_API_VERSION', 'v25.0')),
    'app_id' => env('META_APP_ID'),
    'business_id' => env('META_BUSINESS_ID'),
    'page_id' => env('FACEBOOK_PAGE_ID'),
    'instagram_business_account_id' => env('INSTAGRAM_BUSINESS_ACCOUNT_ID'),
    'page_access_token' => env('META_PAGE_ACCESS_TOKEN'),
    'verify_token' => env('META_WEBHOOK_VERIFY_TOKEN', env('WHATSAPP_VERIFY_TOKEN')),
    'timeout' => (int) env('META_HTTP_TIMEOUT', env('WHATSAPP_HTTP_TIMEOUT', 20)),
];
