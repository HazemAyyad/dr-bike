<?php

namespace App\Console\Commands;

use App\Services\MaintenanceDailyBoxService;
use Illuminate\Console\Command;

class OpenMaintenanceDailySession extends Command
{
    protected $signature = 'maintenance:open-daily-session';

    protected $description = 'Open the shared maintenance daily cash session at 08:00';

    public function handle(MaintenanceDailyBoxService $service): int
    {
        $session = $service->openToday();
        $this->info("Maintenance daily session {$session->id} is {$session->status}.");

        return self::SUCCESS;
    }
}
