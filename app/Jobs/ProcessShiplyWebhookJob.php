<?php

namespace App\Jobs;

use App\Models\SalesOrder;
use App\Models\SalesOrderDelivery;
use App\Models\User;
use App\Services\SalesOrderFulfillmentService;
use App\Support\ShiplySettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ProcessShiplyWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 12;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public array $payload) {}

    public function handle(SalesOrderFulfillmentService $fulfillment): void
    {
        $parcelCode = trim((string) ($this->payload['parcel_code'] ?? ''));
        $statusId = (int) ($this->payload['parcel_status_id'] ?? 0);
        $reference = trim((string) ($this->payload['reference_number'] ?? ''));

        if ($parcelCode === '' && $reference === '') {
            return;
        }

        $delivery = null;
        if ($parcelCode !== '') {
            $delivery = SalesOrderDelivery::query()
                ->where('shiply_parcel_code', $parcelCode)
                ->orWhere('external_reference', $parcelCode)
                ->orWhere('tracking_number', $parcelCode)
                ->latest('id')
                ->first();
        }

        $order = null;
        if ($delivery) {
            $order = SalesOrder::query()->find($delivery->sales_order_id);
        } elseif ($reference !== '') {
            $order = SalesOrder::query()
                ->where('serial_number', $reference)
                ->orWhere('id', is_numeric($reference) ? (int) $reference : 0)
                ->first();
        }

        if (! $order) {
            Log::warning('shiply.webhook_order_not_found', $this->payload);

            return;
        }

        $deliveredStatus = (int) config('shiply.parcel_status.delivered', 6);
        $returnedStatus = (int) config('shiply.parcel_status.returned', 7);

        if ($statusId === $deliveredStatus) {
            if ($order->status === 'delivered') {
                return;
            }

            $actor = $this->resolveActor($delivery, $order);
            try {
                $fulfillment->markDeliveredFromShiply($actor, $order->id, $this->payload);
            } catch (ValidationException $e) {
                if ($this->shouldRetryDeliver($e)) {
                    $delay = max(1, (int) config('shiply.deliver_retry_minutes', 15));
                    self::dispatch($this->payload)->delay(now()->addMinutes($delay));
                } else {
                    Log::error('shiply.webhook_deliver_failed', [
                        'order_id' => $order->id,
                        'errors' => $e->errors(),
                    ]);
                }
            }

            return;
        }

        if ($statusId === $returnedStatus) {
            if (in_array($order->status, ['returned', 'canceled', 'archived'], true)) {
                return;
            }

            $actor = $this->resolveActor($delivery, $order);
            $note = trim((string) ($this->payload['note'] ?? ''));
            $fulfillment->markReturned(
                $actor,
                $order->id,
                $note !== '' ? $note : 'راجع تلقائياً من Shiply'
            );
        }
    }

    private function resolveActor(?SalesOrderDelivery $delivery, SalesOrder $order): User
    {
        if ($delivery?->handed_over_by_user_id) {
            $user = User::query()->find($delivery->handed_over_by_user_id);
            if ($user) {
                return $user;
            }
        }

        if ($order->updated_by) {
            $user = User::query()->find($order->updated_by);
            if ($user) {
                return $user;
            }
        }

        if ($order->created_by) {
            $user = User::query()->find($order->created_by);
            if ($user) {
                return $user;
            }
        }

        return User::query()->where('type', 'admin')->orderBy('id')->firstOrFail();
    }

    private function shouldRetryDeliver(ValidationException $e): bool
    {
        $errors = $e->errors();
        if (isset($errors['session'])) {
            return true;
        }

        $message = json_encode($errors, JSON_UNESCAPED_UNICODE) ?: '';

        return str_contains($message, 'sales_daily');
    }
}
