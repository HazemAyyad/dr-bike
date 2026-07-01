<?php

return [
    'api_version' => env('WHATSAPP_API_VERSION', 'v23.0'),
    'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
    'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
    'display_phone_number' => env('WHATSAPP_DISPLAY_PHONE_NUMBER'),
    'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
    'timeout' => (int) env('WHATSAPP_HTTP_TIMEOUT', 20),
];
