<?php

namespace App\Console\Commands;

use App\Services\CronJobLogger;
use App\Services\EmployeeNotificationService;
use Illuminate\Console\Command;

class EmployeesSendHourlyReminders extends Command
{
    protected $signature = 'employees:send-hourly-reminders {--force : إرسال حتى لو أُرسل تذكير هذه الساعة}';

    protected $description = 'Hourly FCM/in-app reminders: pending tasks and/or attendance check-in (Asia/Hebron work hours)';

    public function handle(
        EmployeeNotificationService $employeeNotificationService,
        CronJobLogger $cronJobLogger
    ): int {
        $force = (bool) $this->option('force');

        return $cronJobLogger->run(
            'employees:send-hourly-reminders',
            function ($buffer, $log) use ($employeeNotificationService, $force) {
                $stats = $employeeNotificationService->sendHourlyReminders($force);
                $log->update([
                    'payload' => array_merge($log->payload ?? [], [
                        'force' => $force,
                        'stats' => $stats,
                    ]),
                ]);

                $this->info(sprintf(
                    'Hourly reminders: employees=%d notified=%d skipped=%d failed=%d',
                    $stats['employees'] ?? 0,
                    $stats['notified'] ?? 0,
                    $stats['skipped'] ?? 0,
                    $stats['failed'] ?? 0
                ));

                return self::SUCCESS;
            },
            'employees:send-hourly-reminders',
            [
                'timezone' => 'Asia/Hebron',
                'force' => $force,
            ],
        );
    }
}
