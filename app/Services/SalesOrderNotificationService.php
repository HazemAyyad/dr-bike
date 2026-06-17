<?php

namespace App\Services;

use App\Enums\SalesOrderStatus;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class SalesOrderNotificationService
{
    public const TYPE_STATUS = 'sales_order_status';

    public const TYPE_SHIPLY_HANDOVER = 'sales_order_shiply_handover';

    public const TYPE_SHIPLY_DELIVERED = 'sales_order_shiply_delivered';

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

    public function notifyShiplyHandover(
        SalesOrder $order,
        User $actor,
        string $parcelCode
    ): void {
        try {
            $serial = $order->serial_number ?? '#'.$order->id;
            $customer = $order->customer_name ?? __('messages.sales_order_unknown_customer');
            $actorName = $actor->name ?? __('messages.employee_default_name');
            $address = trim((string) ($order->customer_address ?? ''));
            if ($address === '' && $order->shiply_city_name) {
                $address = trim(implode(' — ', array_filter([
                    $order->shiply_city_name,
                    $order->shiply_village_name,
                ])));
            }

            $title = __('messages.sales_order_shiply_handover_title', ['serial' => $serial]);
            $body = __('messages.sales_order_shiply_handover_body', [
                'serial' => $serial,
                'customer' => $customer,
                'parcel' => $parcelCode,
                'employee' => $actorName,
                'address' => $address !== '' ? $address : '—',
            ]);

            $this->adminNotifications->create(
                self::TYPE_SHIPLY_HANDOVER,
                $title,
                $body,
                [
                    'sales_order_id' => (string) $order->id,
                    'serial_number' => (string) ($order->serial_number ?? ''),
                    'parcel_code' => $parcelCode,
                    'customer_name' => (string) ($order->customer_name ?? ''),
                    'actor_id' => (string) $actor->id,
                    'actor_name' => $actorName,
                ],
                null,
                'sales_order',
                (int) $order->id,
                true
            );
        } catch (\Throwable $e) {
            Log::error('Shiply handover notification failed: '.$e->getMessage(), [
                'order_id' => $order->id,
                'parcel_code' => $parcelCode,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function notifyShiplyDelivered(
        SalesOrder $order,
        ?User $actor = null,
        array $meta = []
    ): void {
        try {
            $serial = $order->serial_number ?? '#'.$order->id;
            $customer = $order->customer_name ?? __('messages.sales_order_unknown_customer');
            $parcelCode = trim((string) ($meta['parcel_code'] ?? ''));
            if ($parcelCode === '') {
                $parcelCode = trim((string) ($meta['tracking_number'] ?? ''));
            }
            if ($parcelCode === '') {
                $parcelCode = '—';
            }

            $title = __('messages.sales_order_shiply_delivered_title', ['serial' => $serial]);
            $body = __('messages.sales_order_shiply_delivered_body', [
                'serial' => $serial,
                'customer' => $customer,
                'parcel' => $parcelCode,
            ]);

            $this->adminNotifications->create(
                self::TYPE_SHIPLY_DELIVERED,
                $title,
                $body,
                [
                    'sales_order_id' => (string) $order->id,
                    'serial_number' => (string) ($order->serial_number ?? ''),
                    'parcel_code' => $parcelCode,
                    'customer_name' => (string) ($order->customer_name ?? ''),
                    'actor_id' => $actor ? (string) $actor->id : '',
                ],
                null,
                'sales_order',
                (int) $order->id,
                true
            );
        } catch (\Throwable $e) {
            Log::error('Shiply delivered notification failed: '.$e->getMessage(), [
                'order_id' => $order->id,
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
