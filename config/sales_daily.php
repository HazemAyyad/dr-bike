<?php

return [
    'currencies' => ['شيكل', 'دولار', 'دينار'],

    'box_type' => 'daily_sales',

    'variance_note_required' => true,

    'variance_alert_threshold' => 50,

    'max_float' => [
        'شيكل' => 500,
        'دولار' => 200,
        'دينار' => 200,
    ],

    'session_status' => [
        'open' => 'open',
        'closing_requested' => 'closing_requested',
        'closed' => 'closed',
    ],

    'permissions' => [
        'daily_close_review' => 'Sales Daily Close Review',
        'cancel_closed_review' => 'Sales Cancel Closed Review',
    ],
];
