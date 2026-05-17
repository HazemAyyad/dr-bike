<?php

namespace App\Console\Commands;

use App\Services\CronJobLogger;
use App\Services\EmployeeNotificationService;
use Illuminate\Console\Command;

class EmployeesSendDailyTaskReminders extends Command
{
    protected $signature = 'employees:send-daily-task-reminders {--force : إرسال حتى لو أُرسل تذكير اليوم مسبقاً}';

    protected $description = 'Send FCM to employees with pending tasks for today (10:00 Palestine)';

    public function handle(
        EmployeeNotificationService $employeeNotificationService,
        CronJobLogger $cronJobLogger
    ): int {
        $force = (bool) $this->option('force');

        return $cronJobLogger->run(
            'employees:send-daily-task-reminders',
            function () use ($employeeNotificationService, $force) {
                $stats = $employeeNotificationService->sendDailyTaskReminders($force);

                $message = sprintf(
                    'Employees: %d | Notified: %d | Skipped (no tasks/already sent): %d | No FCM token: %d | Failed: %d',
                    $stats['employees'],
                    $stats['notified'],
                    $stats['skipped'],
                    $stats['no_token'],
                    $stats['failed'],
                );

                $this->info($message);

                return self::SUCCESS;
            },
            'employees:send-daily-task-reminders',
            [
                'timezone' => 'Asia/Hebron',
                'force' => $force,
            ],
        );
    }
}
