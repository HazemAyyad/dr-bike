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

    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'from' => env('TWILIO_FROM'),
        'admin_phone' => env('TWILIO_ADMIN_PHONE'),
    ],

    'google_vision' => [
        'api_key' => env('GOOGLE_CLOUD_VISION_API_KEY'),
    ],

    'tuya' => [
        'access_id' => env('TUYA_ACCESS_ID'),
        'access_secret' => env('TUYA_ACCESS_SECRET'),
        'project_id' => env('TUYA_PROJECT_ID'),
        'region' => env('TUYA_REGION', 'central_europe'),
        'country_code' => env('TUYA_COUNTRY_CODE', '970'),
    ],

];
