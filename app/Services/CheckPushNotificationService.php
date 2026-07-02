<?php

namespace App\Services;

use App\Models\CheckNotificationLog;
use App\Models\CheckNotificationRule;
use App\Models\IncomingCheck;
use App\Models\OutgoingCheck;

class CheckPushNotificationService
{
    public function __construct(
        protected CheckSmsNotificationService $messageService,
        protected AdminNotificationService $adminNotificationService
    ) {}

    public function dispatchForAction(IncomingCheck|OutgoingCheck $check, string $eventType): void
    {
        $direction = $check instanceof IncomingCheck ? 'incoming' : 'outgoing';
        $check->loadMissing($check instanceof IncomingCheck
            ? ['fromCustomer', 'fromSeller', 'toCustomer', 'toSeller']
            : ['customer', 'seller']);

        $rules = CheckNotificationRule::query()
            ->where('type', $eventType)
            ->where('check_direction', $direction)
            ->where('channel', 'push')
            ->where('recipient', 'admin')
            ->where('trigger_mode', 'on_action')
            ->where('is_active', true)
            ->get();

        foreach ($rules as $rule) {
            $this->sendForCheck($rule, $check, $eventType);
        }
    }

    public function sendForCheck(
        CheckNotificationRule $rule,
        IncomingCheck|OutgoingCheck $check,
        string $eventType
    ): CheckNotificationLog {
        $direction = $check instanceof IncomingCheck ? 'incoming' : 'outgoing';
        $check->loadMissing($check instanceof IncomingCheck
            ? ['fromCustomer', 'fromSeller', 'toCustomer', 'toSeller']
            : ['customer', 'seller']);

        $message = $this->messageService->renderMessageForCheck($rule->message, $check);

        $log = CheckNotificationLog::firstOrCreate(
            [
                'rule_id' => $rule->id,
                'check_type' => $direction,
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
        $title = $this->titleForEvent($eventType, $direction);

        $checkNumber = (string) ($check->check_id ?? $check->id);
        $dueDate = $check->due_date ? (string) $check->due_date : '';

        $data = [
            'check_id' => (string) $check->id,
            'check_number' => $checkNumber,
            'check_type' => $direction,
            'amount' => (string) ($check->total ?? ''),
            'due_date' => $dueDate,
            'event_type' => $eventType,
            'rule_id' => (string) $rule->id,
        ];

        try {
            $notification = $this->adminNotificationService->create(
                $notificationType,
                $title,
                $message,
                $data,
                null,
                $direction.'_check',
                (int) $check->id,
                false
            );

            $delivery = $this->adminNotificationService->pushToAdminDevices($notification);
            $wasSent = $delivery['sent'] > 0;

            $log->fill([
                'status' => $wasSent ? 'sent' : 'failed',
                'response' => $wasSent
                    ? "Admin push sent to {$delivery['sent']} device(s); {$delivery['failed']} failed."
                    : "Admin push failed: {$delivery['failed']} failed from {$delivery['token_count']} token(s).",
                'sent_at' => $wasSent ? now() : null,
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

    private function titleForEvent(string $eventType, string $direction): string
    {
        $label = $direction === 'incoming' ? 'Incoming' : 'Outgoing';

        return match ($eventType) {
            'cashed' => "{$label} Check Cashed",
            'returned' => "{$label} Check Returned",
            default => "{$label} Check Reminder",
        };
    }
}
