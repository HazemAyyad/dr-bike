<?php

return [
    'allowed_ssids' => array_values(array_filter(array_map(
        static fn ($ssid) => trim($ssid),
        explode(',', env('EMPLOYEE_ALLOWED_WIFI_SSIDS', ''))
    ))),
    'presence_timeout_seconds' => (int) env('EMPLOYEE_WIFI_PRESENCE_TIMEOUT_SECONDS', 120),
];
