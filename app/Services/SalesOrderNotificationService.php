<?php

namespace App\Services;

use App\Enums\SalesOrderStatus;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class SalesOrderNotificationService
{
    public const TYPE_STATUS = 'sales_order_status';

    public function __construct(
        protected AdminNotificationService $adminNotifications
    ) {}

    public function notifyStatusChange(
        SalesOrder $order,
        ?string $fromStatus,
        string $toStatus,
        ?User $actor = null,
        ?string $note = null
    ): void {
        try {
            $serial = $order->serial_number ?? '#'.$order->id;
            $fromLabel = $this->statusLabel($fromStatus);
            $toLabel = $this->statusLabel($toStatus);
            $customer = $order->customer_name ?? __('messages.sales_order_unknown_customer');

            $title = __('messages.sales_order_notify_title', ['serial' => $serial]);
            $body = __('messages.sales_order_notify_body', [
                'serial' => $serial,
                'customer' => $customer,
                'from' => $fromLabel,
                'to' => $toLabel,
            ]);

            if ($note) {
                $body .= ' — '.$note;
            }

            $this->adminNotifications->create(
                self::TYPE_STATUS,
                $title,
                $body,
                [
                    'sales_order_id' => (string) $order->id,
                    'serial_number' => (string) ($order->serial_number ?? ''),
                    'from_status' => (string) ($fromStatus ?? ''),
                    'to_status' => $toStatus,
                    'customer_name' => (string) ($order->customer_name ?? ''),
                    'actor_id' => $actor ? (string) $actor->id : '',
                ],
                null,
                'sales_order',
                (int) $order->id,
                true
            );
        } catch (\Throwable $e) {
            Log::error('Sales order notification failed: '.$e->getMessage(), [
                'order_id' => $order->id,
                'to_status' => $toStatus,
            ]);
        }
    }

    private function statusLabel(?string $status): string
    {
        if (! $status) {
            return '—';
        }

        $key = 'messages.sales_order_status_'.$status;

        return __($key) !== $key ? __($key) : $status;
    }
}
