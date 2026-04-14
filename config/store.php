<?php

return [
    'domain' => rtrim((string) env('STORE_DOMAIN', ''), '/'),
    'email' => env('STORE_EMAIL'),
    'password' => env('STORE_PASSWORD'),
    'sync_stock_on_bill' => filter_var(env('STORE_SYNC_STOCK_ON_BILL', true), FILTER_VALIDATE_BOOLEAN),
];
