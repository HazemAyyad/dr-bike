<?php

namespace App\Console\Commands;

use App\Services\MaintenanceDailyBoxService;
use Illuminate\Console\Command;

class CloseMaintenanceDailySessions extends Command
{
    protected $signature = 'maintenance:close-daily-sessions';

    protected $description = 'Close expired maintenance daily cash sessions after midnight';

    public function handle(MaintenanceDailyBoxService $service): int
    {
        $closed = $service->closeExpiredSessions();
        $this->info("Closed {$closed} maintenance daily session(s).");

        return self::SUCCESS;
    }
}
