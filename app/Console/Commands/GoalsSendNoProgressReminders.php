<?php

namespace App\Console\Commands;

use App\Services\Goals\GoalNotificationService;
use Illuminate\Console\Command;

class GoalsSendNoProgressReminders extends Command
{
    protected $signature = 'goals:send-no-progress-reminders {--force : إرسال حتى لو أُرسل تذكير اليوم}';

    protected $description = 'Send goal reminders when no progress was recorded during the day.';

    public function handle(GoalNotificationService $notifications): int
    {
        $sent = $notifications->sendNoProgressReminders((bool) $this->option('force'));
        $this->info("Goal no-progress reminders sent: {$sent}");

        return self::SUCCESS;
    }
}
