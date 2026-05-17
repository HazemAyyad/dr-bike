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
            function ($buffer, $log) use ($employeeNotificationService, $force) {
                $stats = $employeeNotificationService->sendDailyTaskReminders($force);
                $formatted = $employeeNotificationService->formatDailyReminderReport($stats, $force);

                $log->update([
                    'payload' => array_merge($log->payload ?? [], [
                        'force' => $force,
                        'report' => $formatted['report'],
                    ]),
                ]);

                $this->info($formatted['text']);

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
