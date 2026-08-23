<?php

namespace App\Console\Commands;

use App\Services\Goals\GoalNotificationService;
use Illuminate\Console\Command;

class GoalsSendDailySummary extends Command
{
    protected $signature = 'goals:send-daily-summary {--force : إرسال حتى لو أُرسل لنفس الفترة اليوم}';

    protected $description = 'Send daily goal summary notifications to admins and shared employees.';

    public function handle(GoalNotificationService $notifications): int
    {
        $admin = $notifications->sendAdminDailySummary((bool) $this->option('force'));
        $employees = $notifications->sendEmployeeDailySummaries((bool) $this->option('force'));

        $this->info("Goal summaries sent. Admin: {$admin}, employees: {$employees}");

        return self::SUCCESS;
    }
}
