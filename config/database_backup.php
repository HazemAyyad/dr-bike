<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Database backup settings
    |--------------------------------------------------------------------------
    |
    | The scheduled command stores MySQL dumps in storage/app/backups/database
    | by default. Keep the path outside public storage so backups are not
    | directly downloadable.
    |
    */

    'path' => env('DB_BACKUP_PATH', storage_path('app/backups/database')),

    'keep_days' => (int) env('DB_BACKUP_KEEP_DAYS', 4),

    'mysqldump_binary' => env('MYSQLDUMP_BINARY', 'mysqldump'),
];
