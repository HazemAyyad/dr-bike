<?php

use Carbon\Carbon;

return [
    /*
    |--------------------------------------------------------------------------
    | Attendance / Overtime configuration
    |--------------------------------------------------------------------------
    |
    | - required_work_days_in_month:
    |   If set to a positive integer, monthly required minutes will be computed
    |   using that number of workdays. If null/0, we will count days in the month
    |   that match "workdays".
    |
    | - workdays:
    |   Days-of-week considered as working days when auto-counting. Uses Carbon
    |   constants (Carbon::MONDAY .. Carbon::SUNDAY).
    |
    */
    'required_work_days_in_month' => env('ATTENDANCE_REQUIRED_WORK_DAYS_IN_MONTH'),

    'workdays' => [
        Carbon::MONDAY,
        Carbon::TUESDAY,
        Carbon::WEDNESDAY,
        Carbon::THURSDAY,
        Carbon::FRIDAY,
    ],

    /*
    |--------------------------------------------------------------------------
    | Backward-compatible weekly days off
    |--------------------------------------------------------------------------
    |
    | If an employee does not have weekly_days_off configured (null/empty),
    | we assume these days are off to preserve previous behavior.
    |
    */
    'default_weekly_days_off' => [
        'friday',
    ],
];

