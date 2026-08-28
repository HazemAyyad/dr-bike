<?php

return [
    'daily_box' => [
        'type' => env('SALES_ORDERS_DAILY_BOX_TYPE', 'daily_sales_orders'),
        'name_prefix' => env('SALES_ORDERS_DAILY_BOX_NAME_PREFIX', 'صندوق الطلبيات اليومي'),
    ],
    'payment_box' => [
        'enabled' => env('SALES_ORDERS_DEDICATED_BOX', true),
        'name' => env('SALES_ORDERS_BOX_NAME', 'صندوق الطلبيات'),
        'type' => env('SALES_ORDERS_BOX_TYPE', 'sales_orders'),
        'currency' => env('SALES_ORDERS_BOX_CURRENCY', 'شيكل'),
    ],
];
