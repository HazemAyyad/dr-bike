<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Support\AttendanceSettings;
use App\Support\ShiplySettings;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('checks:send-due-reminders')
            ->dailyAt('00:00')
            ->timezone('Asia/Hebron');

        $schedule->command('maintenance:close-daily-sessions')
            ->dailyAt('00:01')
            ->timezone('Asia/Hebron');

        $schedule->command('maintenance:open-daily-session')
            ->dailyAt('08:00')
            ->timezone('Asia/Hebron');

        $schedule->command('checks:dispatch-sms-notifications')
            ->everyFiveMinutes()
            ->timezone('Asia/Hebron');

        $schedule->command('employees:send-daily-task-reminders')
            ->dailyAt('10:00')
            ->timezone('Asia/Hebron');

        $schedule->command('sales-daily:notify-previous-day-open')
            ->everyThirtyMinutes()
            ->timezone('Asia/Hebron')
            ->between('10:00', '23:59');

        $schedule->command('employees:send-hourly-reminders')
            ->hourly()
            ->timezone('Asia/Hebron')
            ->between('7:00', '21:00');

        $schedule->command('employees:dispatch-task-reminders')
            ->everyFiveMinutes()
            ->timezone('Asia/Hebron');

        $schedule->command('employees:dispatch-operational-reminders')
            ->everyFiveMinutes()
            ->timezone('Asia/Hebron');

        $schedule->command('employees:mark-overdue-tasks')
            ->hourly()
            ->timezone('Asia/Hebron');

        $schedule->command('fingerprint:process-pending')
            ->everyMinute()
            ->timezone('Asia/Hebron');

        $schedule->command('attendance:auto-checkout-open-shifts')
            // Allow after-midnight grace window before auto-closing yesterday.
            ->dailyAt(AttendanceSettings::autoCheckoutCronTime())
            ->timezone('Asia/Hebron');

        $schedule->command('attendance:notify-absent-employees')
            ->dailyAt('15:00')
            ->timezone('Asia/Hebron');

        $schedule->command('shiply:sync-addresses', [
            '--mode' => ShiplySettings::mode(),
        ])
            ->dailyAt('03:00')
            ->timezone('Asia/Hebron');

        $schedule->command('employee-tasks:ensure-occurrences')
            ->dailyAt('00:10')
            ->timezone('Asia/Hebron');

        $schedule->command('database:backup')
            ->hourly()
            ->timezone('Asia/Hebron')
            ->withoutOverlapping()
            ->runInBackground();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
