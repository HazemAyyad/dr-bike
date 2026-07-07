<?php

return [
    'payment_box' => [
        'enabled' => env('SALES_ORDERS_DEDICATED_BOX', true),
        'name' => env('SALES_ORDERS_BOX_NAME', 'صندوق الطلبيات'),
        'type' => env('SALES_ORDERS_BOX_TYPE', 'sales_orders'),
        'currency' => env('SALES_ORDERS_BOX_CURRENCY', 'شيكل'),
    ],
];
