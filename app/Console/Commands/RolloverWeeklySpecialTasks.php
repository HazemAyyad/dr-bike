<?php

namespace App\Console\Commands;

use App\Services\SpecialTasks\SpecialTaskRolloverService;
use Illuminate\Console\Command;

class RolloverWeeklySpecialTasks extends Command
{
    protected $signature = 'special-tasks:rollover-weekly';

    protected $description = 'Move unfinished weekly special tasks to no-date tasks';

    public function handle(SpecialTaskRolloverService $service): int
    {
        $count = $service->moveEndingWeekToNoDate();
        $this->info("Moved {$count} special task(s) to no-date tasks.");

        return self::SUCCESS;
    }
}
