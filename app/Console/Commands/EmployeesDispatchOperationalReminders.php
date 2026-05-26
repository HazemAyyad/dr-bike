<?php

namespace App\Console\Commands;

use App\Services\EmployeeReminderService;
use Illuminate\Console\Command;

class EmployeesDispatchOperationalReminders extends Command
{
    protected $signature = 'employees:dispatch-operational-reminders';

    protected $description = 'Send due operational reminders to employees';

    public function handle(EmployeeReminderService $reminderService): int
    {
        $stats = $reminderService->sendDueNotifications();
        $this->info(sprintf(
            'Operational reminders due=%d sent=%d failed=%d',
            $stats['due'],
            $stats['sent'],
            $stats['failed']
        ));

        return self::SUCCESS;
    }
}
