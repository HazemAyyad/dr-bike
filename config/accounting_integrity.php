<?php

return [
    // Deliberately has no fallback. Read-only inspection remains available,
    // while web repair is disabled until production receives a unique secret.
    'web_repair_token' => env('ACCOUNTING_REPAIR_WEB_TOKEN'),
];
