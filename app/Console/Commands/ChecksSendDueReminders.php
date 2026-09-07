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
                $now = now();
                $reminderDate = $now->toDateString();
                $reminderSlot = $now->format('H:00');
                $dueOn = $now->copy()->addDays(2)->toDateString();

                $incoming = IncomingCheck::query()
                    ->with(['fromCustomer:id,name', 'fromSeller:id,name', 'toCustomer:id,name', 'toSeller:id,name'])
                    ->whereDate('due_date', $dueOn)
                    ->where(function ($q) {
                        $q->where('status', 'not_cashed')->orWhereNull('status');
                    })
                    ->get();

                $outgoing = OutgoingCheck::query()
                    ->with(['customer:id,name', 'seller:id,name'])
                    ->whereDate('due_date', $dueOn)
                    ->where(function ($q) {
                        $q->where('status', 'not_cashed')->orWhereNull('status');
                    })
                    ->get();

                $adminNotificationService->notifyChecksDueSummary(
                    $incoming,
                    $outgoing,
                    $reminderDate,
                    $reminderSlot,
                    $dueOn,
                );

                $message = 'Processed '.$incoming->count().' incoming and '.$outgoing->count().' outgoing checks.';
                $this->info($message);

                return self::SUCCESS;
            },
            'checks:send-due-reminders',
            ['due_in_days' => 2, 'summary' => true],
        );
    }
}
