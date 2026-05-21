<?php

namespace App\Console\Commands;

use App\Services\CronJobLogger;
use App\Services\EmployeeTasks\EmployeeTaskReminderService;
use Illuminate\Console\Command;

class EmployeesDispatchTaskReminders extends Command
{
    protected $signature = 'employees:dispatch-task-reminders';

    protected $description = 'Send per-task scheduled reminders (push/email) from template or legacy task settings';

    public function handle(
        EmployeeTaskReminderService $reminderService,
        CronJobLogger $cronJobLogger
    ): int {
        return $cronJobLogger->run(
            'employees:dispatch-task-reminders',
            function ($buffer, $log) use ($reminderService) {
                $stats = $reminderService->dispatchDueReminders();
                $log->update(['payload' => array_merge($log->payload ?? [], ['stats' => $stats])]);
                $this->info(sprintf(
                    'Task reminders: occurrences=%d legacy=%d sent=%d skipped=%d',
                    $stats['occurrences'],
                    $stats['legacy'],
                    $stats['sent'],
                    $stats['skipped']
                ));

                return self::SUCCESS;
            },
            'employees:dispatch-task-reminders',
            ['timezone' => 'Asia/Hebron']
        );
    }
}
