<?php

return [

    'web_enabled' => env('USER_SESSIONS_WEB_ENABLED', env('APP_ENV') === 'local'),

    /** أنواع المستخدمين المعروضة في الصفحة */
    'types' => ['admin', 'employee'],

];
