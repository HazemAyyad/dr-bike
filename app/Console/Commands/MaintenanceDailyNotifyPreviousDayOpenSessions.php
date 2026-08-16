<?php

namespace App\Console\Commands;

use App\Services\CronJobLogger;
use App\Services\MaintenanceDailyBoxService;
use Illuminate\Console\Command;

class MaintenanceDailyNotifyPreviousDayOpenSessions extends Command
{
    protected $signature = 'maintenance-daily:notify-previous-day-open {--force : إرسال حتى لو أُرسل تذكير لنفس نصف الساعة مسبقاً}';

    protected $description = 'Notify employees and admins every 30 minutes after 10:00 about previous-day maintenance daily sessions left open';

    public function handle(
        MaintenanceDailyBoxService $maintenanceDailyBoxService,
        CronJobLogger $cronJobLogger
    ): int {
        $force = (bool) $this->option('force');

        return $cronJobLogger->run(
            'maintenance-daily:notify-previous-day-open',
            function ($buffer, $log) use ($maintenanceDailyBoxService, $force) {
                $stats = $maintenanceDailyBoxService->sendPreviousDayOpenReminders($force);

                $log->update([
                    'payload' => array_merge($log->payload ?? [], [
                        'force' => $force,
                        'report' => $stats,
                    ]),
                ]);

                $summary = sprintf(
                    'Checked %d previous-day open maintenance session(s). Admin notifications: %d. Employee notifications: %d.',
                    $stats['checked'],
                    $stats['admin_notified'],
                    $stats['employee_notified']
                );

                $this->info($summary);

                return self::SUCCESS;
            },
            'maintenance-daily:notify-previous-day-open',
            [
                'timezone' => 'Asia/Hebron',
                'force' => $force,
            ],
        );
    }
}
