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
            ? ['fromCustomer', 'fromSeller', 'toCustomer', 'toSeller', 'boxes.box']
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
            ? ['fromCustomer', 'fromSeller', 'toCustomer', 'toSeller', 'boxes.box']
            : ['customer', 'seller']);

        $message = $this->messageService->renderMessageForCheck($rule->message, $check);
        $notificationType = $this->notificationTypeForEvent($eventType);
        $title = $this->titleForEvent($eventType, $direction, $check);
        $message = $this->messageForEvent($eventType, $check, $message);

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

        $checkNumber = (string) ($check->check_id ?? $check->id);
        $dueDate = $check->due_date ? (string) $check->due_date : '';

        $data = [
            'check_id' => (string) $check->id,
            'check_number' => $checkNumber,
            'check_type' => $direction,
            'amount' => (string) ($check->total ?? ''),
            'currency' => (string) ($check->currency ?? ''),
            'due_date' => $dueDate,
            'event_type' => $eventType,
            'rule_id' => (string) $rule->id,
            'action_label' => $title,
            'target_name' => $this->targetNameForCheck($check),
            'source_name' => $this->sourceNameForCheck($check),
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

    private function titleForEvent(string $eventType, string $direction, IncomingCheck|OutgoingCheck $check): string
    {
        if ($check instanceof IncomingCheck && $eventType === 'cashed') {
            return $check->status === 'cashed_to_box'
                ? 'صرف شيك وارد للصندوق'
                : 'التصرف في شيك وارد';
        }

        if ($check instanceof OutgoingCheck && $eventType === 'cashed') {
            return $check->status === 'cashed_from_box'
                ? 'صرف شيك صادر من الصندوق'
                : 'صرف شيك صادر لشخص';
        }

        $label = $direction === 'incoming' ? 'وارد' : 'صادر';

        return match ($eventType) {
            'cashed' => "صرف شيك {$label}",
            'returned' => "إرجاع شيك {$label}",
            default => "تذكير شيك {$label}",
        };
    }

    private function messageForEvent(
        string $eventType,
        IncomingCheck|OutgoingCheck $check,
        string $fallback
    ): string {
        if (! in_array($eventType, ['cashed', 'returned'], true)) {
            return $fallback;
        }

        $direction = $check instanceof IncomingCheck ? 'وارد' : 'صادر';
        $checkNumber = $this->checkNumber($check);
        $amount = $this->amountLabel($check);
        $bank = trim((string) ($check->bank_name ?? ''));
        $bankPart = $bank !== '' ? " - البنك: {$bank}" : '';

        if ($eventType === 'returned') {
            $sourceName = $this->sourceNameForCheck($check);
            $sourcePart = $sourceName !== '' ? " من {$sourceName}" : '';

            return "تم إرجاع شيك {$direction} رقم {$checkNumber} بقيمة {$amount}{$sourcePart}{$bankPart}";
        }

        if ($check instanceof IncomingCheck) {
            $sourceName = $this->sourceNameForCheck($check);
            $targetName = $this->targetNameForCheck($check);
            $sourcePart = $sourceName !== '' ? " من {$sourceName}" : '';
            $targetPart = $targetName !== '' ? " إلى {$targetName}" : '';
            $action = $check->status === 'cashed_to_box'
                ? 'تم صرف'
                : 'تم التصرف في';

            return "{$action} شيك وارد رقم {$checkNumber} بقيمة {$amount}{$sourcePart}{$targetPart}{$bankPart}";
        }

        $targetName = $this->targetNameForCheck($check);
        $targetPart = $targetName !== '' ? " إلى {$targetName}" : '';

        return "تم صرف شيك صادر رقم {$checkNumber} بقيمة {$amount}{$targetPart}{$bankPart}";
    }

    private function checkNumber(IncomingCheck|OutgoingCheck $check): string
    {
        $number = trim((string) ($check->check_id ?? ''));

        return $number !== '' ? $number : (string) $check->id;
    }

    private function amountLabel(IncomingCheck|OutgoingCheck $check): string
    {
        $amount = (string) ($check->total ?? '');
        $currency = trim((string) ($check->currency ?? ''));

        return trim($amount.' '.$currency);
    }

    private function sourceNameForCheck(IncomingCheck|OutgoingCheck $check): string
    {
        if ($check instanceof IncomingCheck) {
            return (string) (
                $check->fromCustomer?->name
                ?? $check->fromSeller?->name
                ?? ''
            );
        }

        return '';
    }

    private function targetNameForCheck(IncomingCheck|OutgoingCheck $check): string
    {
        if ($check instanceof IncomingCheck) {
            if ($check->status === 'cashed_to_box') {
                $box = $check->boxes->last()?->box;

                return $box ? 'الصندوق '.$box->name : 'الصندوق';
            }

            if ($check->toCustomer) {
                return 'الزبون '.$check->toCustomer->name;
            }

            if ($check->toSeller) {
                return 'المورد '.$check->toSeller->name;
            }

            return '';
        }

        if ($check->customer) {
            return 'الزبون '.$check->customer->name;
        }

        if ($check->seller) {
            return 'المورد '.$check->seller->name;
        }

        return '';
    }
}
