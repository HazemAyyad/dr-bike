<?php

namespace App\Observers;

use App\Models\BoxLog;
use App\Services\BoxMovementNotificationService;

class BoxMovementNotificationObserver
{
    public function created(BoxLog $log): void
    {
        // Capture balances now, while the movement's transaction still holds
        // its locks. Delivery is deferred until that transaction commits.
        app(BoxMovementNotificationService::class)->recordLog($log);
    }
}
