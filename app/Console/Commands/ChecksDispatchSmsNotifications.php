<?php

namespace App\Console\Commands;

use App\Models\CheckNotificationRule;
use App\Models\IncomingCheck;
use App\Models\OutgoingCheck;
use App\Services\CheckSmsNotificationService;
use App\Services\CheckPushNotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ChecksDispatchSmsNotifications extends Command
{
    protected $signature = 'checks:dispatch-sms-notifications';

    protected $description = 'Dispatch scheduled SMS notifications for checks.';

    public function handle(
        CheckSmsNotificationService $smsService,
        CheckPushNotificationService $pushService
    ): int
    {
        $now = now();
        $sent = 0;

        $rules = CheckNotificationRule::query()
            ->where('is_active', true)
            ->where('trigger_mode', 'at_time')
            ->get();

        foreach ($rules as $rule) {
            if (! $this->isDueTime($rule->send_time, $now)) {
                continue;
            }

            $checks = $this->checksForRule($rule);
            foreach ($checks as $check) {
                $log = $rule->channel === 'push'
                    ? $pushService->sendForCheck($rule, $check, $rule->type)
                    : $smsService->sendForCheck($rule, $check, $rule->type);
                if ($log->wasRecentlyCreated || $log->sent_at) {
                    $sent++;
                }
            }
        }

        $this->info("Processed {$sent} check SMS notifications.");

        return self::SUCCESS;
    }

    private function isDueTime(?string $sendTime, Carbon $now): bool
    {
        if (! $sendTime) {
            return false;
        }

        [$hour, $minute] = array_map('intval', explode(':', substr($sendTime, 0, 5)));
        $target = $now->copy()->setTime($hour, $minute);

        return $now->betweenIncluded($target, $target->copy()->addMinutes(4));
    }

    private function checksForRule(CheckNotificationRule $rule)
    {
        if ($rule->type === 'before_due') {
            $dueOn = now()->copy()->addDays($rule->days)->toDateString();

            $query = $rule->check_direction === 'incoming'
                ? IncomingCheck::query()->with(['fromCustomer', 'fromSeller', 'toCustomer', 'toSeller'])
                : OutgoingCheck::query()->with(['customer', 'seller']);

            return $query
                ->whereDate('due_date', $dueOn)
                ->where(function ($q) {
                    $this->applyNotCashedScope($q);
                })
                ->get();
        }

        $statuses = $rule->type === 'cashed'
            ? ['cashed', 'cashed_to_box', 'cashed_to_person', 'cashed_from_box']
            : ['returned'];
        $actionDate = now()->copy()->subDays($rule->days)->toDateString();

        $query = $rule->check_direction === 'incoming'
            ? IncomingCheck::query()->with(['fromCustomer', 'fromSeller', 'toCustomer', 'toSeller'])
            : OutgoingCheck::query()->with(['customer', 'seller']);

        return $query
            ->whereIn('status', $statuses)
            ->whereDate('updated_at', $actionDate)
            ->get();
    }

    private function applyNotCashedScope($query): void
    {
        $query->where(function ($q) {
            $q->where('status', 'not_cashed')->orWhereNull('status');
        });
    }
}
