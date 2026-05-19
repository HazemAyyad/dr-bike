<?php

namespace App\Console\Commands;

use App\Services\EmployeeTasks\EmployeeTaskOverdueService;
use Illuminate\Console\Command;

class MarkOverdueEmployeeTasks extends Command
{
    protected $signature = 'employees:mark-overdue-tasks';

    protected $description = 'Mark expired employee tasks as overdue and notify assignees';

    public function handle(EmployeeTaskOverdueService $service): int
    {
        $count = $service->markOverdueTasks();
        $this->info("Marked {$count} task(s) as overdue.");

        return self::SUCCESS;
    }
}
