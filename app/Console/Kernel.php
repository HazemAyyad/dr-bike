<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('checks:send-due-reminders')->dailyAt('00:00');

        $schedule->command('employees:send-daily-task-reminders')
            ->dailyAt('10:00')
            ->timezone('Asia/Hebron');

        $schedule->command('employees:mark-overdue-tasks')
            ->hourly()
            ->timezone('Asia/Hebron');
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
