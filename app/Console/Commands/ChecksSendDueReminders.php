<?php

namespace App\Console\Commands;

use App\Models\IncomingCheck;
use App\Models\OutgoingCheck;
use App\Services\AdminNotificationService;
use App\Services\CronJobLogger;
use Illuminate\Console\Command;

class ChecksSendDueReminders extends Command
{
    protected $signature = 'checks:send-due-reminders';

    protected $description = 'Create admin notifications and FCM for checks due in two days';

    public function handle(AdminNotificationService $adminNotificationService, CronJobLogger $cronJobLogger): int
    {
        return $cronJobLogger->run(
            'checks:send-due-reminders',
            function () use ($adminNotificationService) {
                $reminderDate = now()->toDateString();
                $dueOn = now()->addDays(2)->toDateString();

                $incoming = IncomingCheck::query()
                    ->whereDate('due_date', $dueOn)
                    ->where(function ($q) {
                        $q->where('status', 'not_cashed')->orWhereNull('status');
                    })
                    ->get();

                foreach ($incoming as $check) {
                    $adminNotificationService->notifyCheckDueSoon($check, 'incoming', $reminderDate);
                }

                $outgoing = OutgoingCheck::query()
                    ->whereDate('due_date', $dueOn)
                    ->where(function ($q) {
                        $q->where('status', 'not_cashed')->orWhereNull('status');
                    })
                    ->get();

                foreach ($outgoing as $check) {
                    $adminNotificationService->notifyCheckDueSoon($check, 'outgoing', $reminderDate);
                }

                $message = 'Processed '.$incoming->count().' incoming and '.$outgoing->count().' outgoing checks.';
                $this->info($message);

                return self::SUCCESS;
            },
            'checks:send-due-reminders',
            ['due_in_days' => 2],
        );
    }
}
