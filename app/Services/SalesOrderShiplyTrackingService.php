<?php

namespace App\Services;

use App\Models\SalesOrder;
use App\Models\SalesOrderDelivery;
use App\Models\SalesOrderShiplyEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class SalesOrderShiplyTrackingService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordFromWebhook(SalesOrder $order, array $payload): ?SalesOrderShiplyEvent
    {
        $statusId = (int) ($payload['parcel_status_id'] ?? 0);
        if ($statusId <= 0) {
            return null;
        }

        $parcelCode = trim((string) ($payload['parcel_code'] ?? ''));
        if ($parcelCode === '') {
            $parcelCode = $this->resolveParcelCode($order);
        }

        if ($parcelCode === '') {
            return null;
        }

        return $this->recordIfNew(
            order: $order,
            parcelCode: $parcelCode,
            statusId: $statusId,
            positionId: isset($payload['parcel_position_id']) ? (int) $payload['parcel_position_id'] : null,
            note: trim((string) ($payload['note'] ?? '')) ?: null,
            mode: $this->resolveShiplyMode($order),
            source: 'webhook',
        );
    }

    public function recordHandoverSubmitted(SalesOrder $order, string $parcelCode, string $mode): SalesOrderShiplyEvent
    {
        $submitted = (int) config('shiply.parcel_status.submitted', 2);

        return $this->record(
            order: $order,
            parcelCode: $parcelCode,
            statusId: $submitted,
            note: __('messages.shiply_event_handover_submitted'),
            mode: $mode,
            source: 'handover',
        );
    }

    public function record(
        SalesOrder $order,
        string $parcelCode,
        int $statusId,
        ?int $positionId = null,
        ?string $note = null,
        ?string $mode = null,
        string $source = 'webhook',
        ?\DateTimeInterface $occurredAt = null,
    ): SalesOrderShiplyEvent {
        $event = $this->recordIfNew(
            order: $order,
            parcelCode: $parcelCode,
            statusId: $statusId,
            positionId: $positionId,
            note: $note,
            mode: $mode,
            source: $source,
            occurredAt: $occurredAt,
        );

        if ($event !== null) {
            return $event;
        }

        return SalesOrderShiplyEvent::query()
            ->where('sales_order_id', $order->id)
            ->where('parcel_code', $parcelCode)
            ->where('parcel_status_id', $statusId)
            ->latest('id')
            ->firstOrFail();
    }

    public function recordIfNew(
        SalesOrder $order,
        string $parcelCode,
        int $statusId,
        ?int $positionId = null,
        ?string $note = null,
        ?string $mode = null,
        string $source = 'webhook',
        ?\DateTimeInterface $occurredAt = null,
    ): ?SalesOrderShiplyEvent {
        $duplicate = SalesOrderShiplyEvent::query()
            ->where('sales_order_id', $order->id)
            ->where('parcel_code', $parcelCode)
            ->where('parcel_status_id', $statusId)
            ->where('source', $source)
            ->where('occurred_at', '>=', now()->subMinutes(2))
            ->exists();

        if ($duplicate) {
            return null;
        }

        return SalesOrderShiplyEvent::create([
            'sales_order_id' => $order->id,
            'parcel_code' => $parcelCode,
            'parcel_status_id' => $statusId,
            'parcel_position_id' => $positionId,
            'note' => $note,
            'shiply_mode' => $mode,
            'source' => $source,
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buildTrackingPayload(SalesOrder $order): ?array
    {
        if (! Schema::hasTable('sales_order_shiply_events')) {
            return null;
        }

        $parcelCode = $this->resolveParcelCode($order);
        if ($parcelCode === '') {
            return null;
        }

        $events = SalesOrderShiplyEvent::query()
            ->where('sales_order_id', $order->id)
            ->where('parcel_code', $parcelCode)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        if ($events->isEmpty()) {
            return null;
        }

        $currentStatusId = (int) $events->last()->parcel_status_id;
        $mode = $events->last()->shiply_mode ?? $this->resolveShiplyMode($order);

        return [
            'parcel_code' => $parcelCode,
            'shiply_mode' => $mode,
            'current_status_id' => $currentStatusId,
            'current_status_key' => $this->statusKey($currentStatusId),
            'current_status_label' => $this->statusLabel($currentStatusId),
            'status_sequence' => $this->statusSequence(),
            'events' => $this->formatEvents($events),
        ];
    }

    /**
     * @return list<int>
     */
    public function statusSequence(): array
    {
        return array_values(config('shiply.parcel_status', []));
    }

    public function statusKey(int $statusId): string
    {
        $map = array_flip(config('shiply.parcel_status', []));

        return (string) ($map[$statusId] ?? 'unknown');
    }

    public function statusLabel(int $statusId): string
    {
        $key = $this->statusKey($statusId);
        $messageKey = 'shiply_parcel_status_'.$key;

        return __($messageKey) !== $messageKey ? __($messageKey) : $key;
    }

    private function resolveParcelCode(SalesOrder $order): string
    {
        $delivery = $this->latestShiplyDelivery($order);

        return trim((string) ($delivery?->shiply_parcel_code ?? $delivery?->tracking_number ?? ''));
    }

    private function resolveShiplyMode(SalesOrder $order): ?string
    {
        return $this->latestShiplyDelivery($order)?->shiply_mode;
    }

    private function latestShiplyDelivery(SalesOrder $order): ?SalesOrderDelivery
    {
        if ($order->relationLoaded('deliveries')) {
            return $order->deliveries
                ->filter(fn (SalesOrderDelivery $d) => ! empty($d->shiply_parcel_code))
                ->sortByDesc('id')
                ->first();
        }

        return SalesOrderDelivery::query()
            ->where('sales_order_id', $order->id)
            ->whereNotNull('shiply_parcel_code')
            ->latest('id')
            ->first();
    }

    /**
     * @param  Collection<int, SalesOrderShiplyEvent>  $events
     * @return list<array<string, mixed>>
     */
    private function formatEvents(Collection $events): array
    {
        $formatted = [];
        $lastStatusId = null;

        foreach ($events as $event) {
            if ($lastStatusId === (int) $event->parcel_status_id) {
                continue;
            }

            $lastStatusId = (int) $event->parcel_status_id;
            $formatted[] = [
                'id' => $event->id,
                'parcel_status_id' => (int) $event->parcel_status_id,
                'status_key' => $this->statusKey((int) $event->parcel_status_id),
                'status_label' => $this->statusLabel((int) $event->parcel_status_id),
                'note' => $event->note,
                'source' => $event->source,
                'occurred_at' => $event->occurred_at?->toDateTimeString(),
            ];
        }

        return $formatted;
    }
}
