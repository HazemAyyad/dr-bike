<?php

// Avoid POST body loss: many hosts redirect http→https; clients often follow with GET and drop the body.
$domain = rtrim((string) env('STORE_DOMAIN', ''), '/');
if ($domain !== '' && str_starts_with($domain, 'http://')) {
    $domain = 'https://'.substr($domain, strlen('http://'));
}

return [
    'domain' => $domain,
    'email' => env('STORE_EMAIL') !== null ? trim((string) env('STORE_EMAIL')) : null,
    'password' => env('STORE_PASSWORD') !== null ? trim((string) env('STORE_PASSWORD')) : null,
    'sync_stock_on_bill' => filter_var(env('STORE_SYNC_STOCK_ON_BILL', true), FILTER_VALIDATE_BOOLEAN),
];
