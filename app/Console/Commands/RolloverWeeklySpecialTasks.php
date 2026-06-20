<?php

namespace App\Console\Commands;

use App\Services\SpecialTasks\SpecialTaskRolloverService;
use Illuminate\Console\Command;

class RolloverWeeklySpecialTasks extends Command
{
    protected $signature = 'special-tasks:rollover-weekly';

    protected $description = 'Move incomplete expired special tasks to the next week';

    public function handle(SpecialTaskRolloverService $service): int
    {
        $count = $service->rolloverToNextWeek();
        $this->info("Rolled over {$count} special task(s) to the next week.");

        return self::SUCCESS;
    }
}
