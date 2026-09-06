<?php

namespace App\Services;

use App\Models\EmployeeActivityLog;
use App\Models\InstantSale;
use App\Models\InstantSaleRevision;
use App\Models\Product;
use App\Models\SuspendedInstantSale;
use Illuminate\Support\Facades\Schema;

class InstantSaleHistoryService
{
    /** @return array<string, mixed> */
    public function snapshot(InstantSale|int $sale): array
    {
        $id = $sale instanceof InstantSale ? (int) $sale->id : $sale;
        $sale = InstantSale::query()
            ->whereNull('parent_id')
            ->with([
                'product:id,nameAr,product_code',
                'offerPackage:id,name',
                'size:id,size',
                'sizeColor.size:id,size',
                'subProducts.product:id,nameAr,product_code',
                'subProducts.size:id,size',
                'subProducts.sizeColor.size:id,size',
                'createdByUser:id,name',
                'updatedByUser:id,name',
            ])
            ->findOrFail($id);

        $lines = collect([$sale])->concat($sale->subProducts)->map(function (InstantSale $line) {
            $name = $line->offer_package_id
                ? ($line->offerPackage?->name ?? 'باكيج محذوف')
                : ($line->product?->nameAr ?? 'منتج محذوف');

            return [
                'id' => (int) $line->id,
                'product_id' => $line->product_id ? (int) $line->product_id : null,
                'offer_package_id' => $line->offer_package_id ? (int) $line->offer_package_id : null,
                'name' => $name,
                'product_code' => $line->product?->product_code,
                'variant' => $this->variantLabel($line),
                'quantity' => (float) $line->quantity,
                'unit_price' => (float) $line->cost,
                'subtotal' => (float) $line->cost * (float) $line->quantity,
            ];
        })->values()->all();

        return [
            'invoice_number' => (string) ($sale->serial_number ?: 'SAL-'.str_pad((string) $sale->id, 7, '0', STR_PAD_LEFT)),
            'total_cost' => (float) $sale->total_cost,
            'discount' => (float) ($sale->discount ?? 0),
            'paid_amount' => (float) ($sale->payment_box_value ?? 0),
            'remaining_amount' => max(0, (float) $sale->total_cost - (float) ($sale->payment_box_value ?? 0)),
            'payment_box_id' => $sale->payment_box_id ? (int) $sale->payment_box_id : null,
            'payment_box_name' => $sale->payment_box_name,
            'buyer_type' => $sale->buyer_type,
            'buyer_name' => $sale->buyer_name,
            'buyer_phone' => $sale->buyer_phone,
            'notes' => $sale->notes,
            'additional_notes' => $sale->additional_notes ?? [],
            'status' => $sale->isCancelled() ? 'cancelled' : ($sale->status ?? 'active'),
            'created_by_name' => $sale->createdByUser?->name,
            'updated_by_name' => $sale->updatedByUser?->name,
            'lines' => $lines,
        ];
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @param array<string, mixed> $metadata
     */
    public function record(
        int $saleId,
        string $action,
        ?array $before,
        ?array $after,
        ?string $reason = null,
        array $metadata = []
    ): void {
        if (! Schema::hasTable('instant_sale_revisions')) {
            return;
        }

        InstantSaleRevision::create([
            'instant_sale_id' => $saleId,
            'actor_user_id' => auth()->id(),
            'action' => $action,
            'before_snapshot' => $before,
            'after_snapshot' => $after,
            'reason' => $reason,
            'metadata' => $metadata,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public function timeline(InstantSale $sale): array
    {
        $items = [];

        if (Schema::hasTable('instant_sale_revisions')) {
            $revisions = InstantSaleRevision::query()
                ->with('actor:id,name')
                ->where('instant_sale_id', $sale->id)
                ->oldest('created_at')
                ->get();

            foreach ($revisions as $revision) {
                $items[] = [
                    'id' => 'revision-'.$revision->id,
                    'action' => $revision->action,
                    'title' => $this->actionTitle($revision->action),
                    'description' => $revision->reason,
                    'actor_name' => $revision->actor?->name,
                    'occurred_at' => optional($revision->created_at)->format('Y-m-d H:i:s'),
                    'before' => $revision->before_snapshot,
                    'after' => $revision->after_snapshot,
                    'source' => 'revision',
                ];
            }
        }

        $hasRevisionHistory = $items !== [];

        // The suspended record explains who originally added the invoice, even
        // when a different employee completed or edited it later.
        if (Schema::hasTable('suspended_instant_sales')) {
            $suspended = SuspendedInstantSale::query()
                ->with(['createdByUser:id,name', 'completedByUser:id,name'])
                ->where('completed_instant_sale_id', $sale->id)
                ->oldest('completed_at')
                ->get();

            foreach ($suspended as $record) {
                $items[] = [
                    'id' => 'suspended-'.$record->id,
                    'action' => 'completed_suspended',
                    'title' => 'إتمام فاتورة معلقة '.($record->reference_code ?? '#'.$record->id),
                    'description' => $record->summary_label,
                    'actor_name' => $record->completedByUser?->name,
                    'created_by_name' => $record->createdByUser?->name,
                    'occurred_at' => optional($record->completed_at)->format('Y-m-d H:i:s'),
                    'before' => null,
                    'after' => $this->snapshotFromSuspendedPayload($record->payload ?? [], $record->total_cost),
                    'source' => 'suspended',
                ];
            }
        }

        // Older invoices predate revision snapshots. Use the employee activity
        // log as a readable fallback without duplicating new revision entries.
        if (! $hasRevisionHistory && Schema::hasTable('employee_activity_logs')) {
            $logs = EmployeeActivityLog::query()
                ->with('actor:id,name')
                ->where('subject_type', 'instant_sale')
                ->where('subject_id', $sale->id)
                ->oldest('created_at')
                ->get();

            foreach ($logs as $log) {
                $items[] = [
                    'id' => 'activity-'.$log->id,
                    'action' => $log->action,
                    'title' => $log->title,
                    'description' => $log->description,
                    'actor_name' => $log->actor?->name,
                    'occurred_at' => optional($log->created_at)->format('Y-m-d H:i:s'),
                    'before' => null,
                    'after' => null,
                    'amount' => $log->amount,
                    'source' => 'activity',
                ];
            }
        }

        usort($items, fn (array $a, array $b) => strcmp((string) ($b['occurred_at'] ?? ''), (string) ($a['occurred_at'] ?? '')));

        return $items;
    }

    private function variantLabel(InstantSale $line): ?string
    {
        $size = $line->sizeColor?->size?->size ?? $line->size?->size;
        $color = $line->sizeColor?->colorAr;
        $parts = array_values(array_filter([$size, $color], fn ($value) => filled($value)));

        return $parts === [] ? null : implode(' - ', $parts);
    }

    /** @return array<string, mixed> */
    private function snapshotFromSuspendedPayload(array $payload, mixed $storedTotal): array
    {
        $productIds = collect([$payload['product_id'] ?? null])
            ->concat(collect($payload['other_products'] ?? [])->pluck('product_id'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique();
        $names = Product::query()->whereIn('id', $productIds)->pluck('nameAr', 'id');
        $lines = [];

        if (! empty($payload['product_id'])) {
            $id = (int) $payload['product_id'];
            $lines[] = $this->payloadLine($payload, $names[$id] ?? 'منتج محذوف');
        }
        foreach ($payload['other_products'] ?? [] as $line) {
            $id = (int) ($line['product_id'] ?? 0);
            $lines[] = $this->payloadLine($line, $names[$id] ?? 'منتج محذوف');
        }

        return [
            'total_cost' => (float) ($payload['total_cost'] ?? $storedTotal ?? 0),
            'discount' => (float) ($payload['discount'] ?? 0),
            'paid_amount' => (float) ($payload['payment_box_value'] ?? 0),
            'payment_box_name' => $payload['payment_box_name'] ?? null,
            'buyer_type' => $payload['buyer_type'] ?? null,
            'buyer_name' => $payload['buyer_name'] ?? null,
            'buyer_phone' => $payload['buyer_phone'] ?? null,
            'notes' => $payload['notes'] ?? null,
            'additional_notes' => $payload['additional_notes'] ?? [],
            'lines' => $lines,
        ];
    }

    /** @return array<string, mixed> */
    private function payloadLine(array $line, string $name): array
    {
        $quantity = (float) ($line['quantity'] ?? 1);
        $price = (float) ($line['cost'] ?? 0);

        return [
            'product_id' => isset($line['product_id']) ? (int) $line['product_id'] : null,
            'name' => $name,
            'quantity' => $quantity,
            'unit_price' => $price,
            'subtotal' => $price * $quantity,
        ];
    }

    private function actionTitle(string $action): string
    {
        return match ($action) {
            'created' => 'إنشاء الفاتورة',
            'updated' => 'تعديل الفاتورة',
            'cancelled' => 'إلغاء الفاتورة',
            default => 'حركة على الفاتورة',
        };
    }
}
