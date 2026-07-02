<?php

return [
    'api_version' => env('WHATSAPP_API_VERSION', 'v23.0'),
    'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
    'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
    'display_phone_number' => env('WHATSAPP_DISPLAY_PHONE_NUMBER'),
    'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
    'timeout' => (int) env('WHATSAPP_HTTP_TIMEOUT', 20),
    'welcome_enabled' => filter_var(env('WHATSAPP_WELCOME_ENABLED', true), FILTER_VALIDATE_BOOL),
    'welcome_cooldown_hours' => (int) env('WHATSAPP_WELCOME_COOLDOWN_HOURS', 24),
    'welcome_message' => env(
        'WHATSAPP_WELCOME_MESSAGE',
        "أهلًا بك في د. بايك 👋\nتم استلام رسالتك وسيقوم أحد الموظفين بالرد عليك قريبًا."
    ),
    'welcome_menu_enabled' => filter_var(
        env('WHATSAPP_WELCOME_MENU_ENABLED', true),
        FILTER_VALIDATE_BOOL
    ),
    'reengagement_template_name' => env(
        'WHATSAPP_REENGAGEMENT_TEMPLATE_NAME',
        'continue_with_doctor_bike'
    ),
    'reengagement_template_language' => env(
        'WHATSAPP_REENGAGEMENT_TEMPLATE_LANGUAGE',
        'ar'
    ),
];
