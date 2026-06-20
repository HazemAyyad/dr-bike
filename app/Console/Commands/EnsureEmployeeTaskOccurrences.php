<?php

namespace App\Console\Commands;

use App\Services\EmployeeTasks\EmployeeTaskRecurrenceService;
use Illuminate\Console\Command;

class EnsureEmployeeTaskOccurrences extends Command
{
    protected $signature = 'employee-tasks:ensure-occurrences';

    protected $description = 'Generate upcoming employee task occurrences for active recurring templates';

    public function handle(EmployeeTaskRecurrenceService $recurrence): int
    {
        $recurrence->ensureActiveTemplateOccurrences();
        $this->info('Ensured upcoming occurrences for active recurring templates.');

        return self::SUCCESS;
    }
}
