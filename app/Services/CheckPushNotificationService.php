<?php

namespace App\Services;

use App\Models\CheckNotificationLog;
use App\Models\CheckNotificationRule;
use App\Models\OutgoingCheck;

class CheckPushNotificationService
{
    public function __construct(
        protected CheckSmsNotificationService $messageService,
        protected AdminNotificationService $adminNotificationService
    ) {}

    public function dispatchForAction(OutgoingCheck $check, string $eventType): void
    {
        $check->loadMissing(['customer', 'seller']);

        $rules = CheckNotificationRule::query()
            ->where('type', $eventType)
            ->where('trigger_mode', 'on_action')
            ->where('is_active', true)
            ->get();

        foreach ($rules as $rule) {
            $this->sendForCheck($rule, $check, $eventType);
        }
    }

    public function sendForCheck(
        CheckNotificationRule $rule,
        OutgoingCheck $check,
        string $eventType
    ): CheckNotificationLog {
        $check->loadMissing(['customer', 'seller']);

        $message = $this->messageService->renderMessageForCheck($rule->message, $check);

        $log = CheckNotificationLog::firstOrCreate(
            [
                'rule_id' => $rule->id,
                'check_type' => 'outgoing',
                'check_id' => $check->id,
                'event_type' => $eventType,
            ],
            [
                'phone' => null,
                'message' => $message,
                'status' => 'pending',
            ]
        );

        if ($log->sent_at) {
            return $log;
        }

        $log->fill([
            'message' => $message,
        ]);

        $notificationType = $this->notificationTypeForEvent($eventType);
        $title = $this->titleForEvent($eventType);

        $checkNumber = (string) ($check->check_id ?? $check->id);
        $dueDate = $check->due_date ? (string) $check->due_date : '';

        $data = [
            'check_id' => (string) $check->id,
            'check_number' => $checkNumber,
            'check_type' => 'outgoing',
            'amount' => (string) ($check->total ?? ''),
            'due_date' => $dueDate,
            'event_type' => $eventType,
            'rule_id' => (string) $rule->id,
        ];

        try {
            $this->adminNotificationService->create(
                $notificationType,
                $title,
                $message,
                $data,
                null,
                'outgoing_check',
                (int) $check->id,
                true
            );

            $log->fill([
                'status' => 'sent',
                'response' => 'Admin push notification sent.',
                'sent_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            $log->fill([
                'status' => 'failed',
                'response' => $e->getMessage(),
            ])->save();
        }

        return $log;
    }

    private function notificationTypeForEvent(string $eventType): string
    {
        return match ($eventType) {
            'cashed' => AdminNotificationService::TYPE_CHECK_CASHED,
            'returned' => AdminNotificationService::TYPE_CHECK_RETURNED,
            default => AdminNotificationService::TYPE_CHECK_DUE_REMINDER,
        };
    }

    private function titleForEvent(string $eventType): string
    {
        return match ($eventType) {
            'cashed' => 'Outgoing Check Cashed',
            'returned' => 'Outgoing Check Returned',
            default => 'Outgoing Check Reminder',
        };
    }
}
