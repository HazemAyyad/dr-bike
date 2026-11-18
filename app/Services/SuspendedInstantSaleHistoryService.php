<?php

namespace App\Services;

use App\Models\OfferPackage;
use App\Models\Product;
use App\Models\Size;
use App\Models\SizeColor;
use App\Models\SuspendedInstantSale;
use App\Models\SuspendedInstantSaleRevision;
use App\Models\User;

class SuspendedInstantSaleHistoryService
{
    public function payloadHash(SuspendedInstantSale $record): string
    {
        return hash('sha256', json_encode([
            $record->payload, $record->current_step, $record->save_type, $record->status,
        ], JSON_THROW_ON_ERROR));
    }

    /** Store both the raw payload and readable product/variant names at this time. */
    public function snapshot(SuspendedInstantSale $record): array
    {
        $payload = $record->payload ?? [];
        $rawLines = array_merge([$payload], $payload['other_products'] ?? []);
        $products = Product::withTrashed()->whereIn('id', array_column($rawLines, 'product_id'))
            ->get(['id', 'nameAr', 'product_code'])->keyBy('id');
        $sizes = Size::whereIn('id', array_column($rawLines, 'size_id'))->pluck('size', 'id');
        $colors = SizeColor::with('size:id,size')
            ->whereIn('id', array_column($rawLines, 'size_color_id'))->get()->keyBy('id');
        $packages = OfferPackage::with(['items.product' => fn ($query) => $query->withTrashed()])
            ->whereIn('id', array_column($rawLines, 'offer_package_id'))->get()->keyBy('id');
        $lines = [];
        foreach ($rawLines as $line) {
            if (empty($line['product_id']) && empty($line['offer_package_id'])) {
                continue;
            }
            $product = $products->get($line['product_id'] ?? null);
            $color = $colors->get($line['size_color_id'] ?? null);
            $package = $packages->get($line['offer_package_id'] ?? null);
            $quantity = (float) ($line['quantity'] ?? 1);
            $price = (float) ($line['cost'] ?? 0);
            $lines[] = array_merge(\Illuminate\Support\Arr::only($line, [
                'product_id', 'offer_package_id', 'size_id', 'size_color_id', 'is_bonus', 'type', 'project_id',
            ]), [
                'name' => ! empty($line['offer_package_id'])
                    ? ($package?->name ?? 'باكيج محذوف')
                    : ($product?->nameAr ?? 'منتج محذوف'),
                'product_code' => $product?->product_code,
                'size_label' => $color?->size?->size ?? ($sizes[$line['size_id'] ?? null] ?? null),
                'color_label' => $color?->colorAr,
                'quantity' => $quantity,
                'unit_price' => $price,
                'subtotal' => $quantity * $price,
                'package_items' => $package?->items->map(fn ($item) => [
                    'product_id' => $item->product_id,
                    'name' => $item->product?->nameAr ?? 'منتج محذوف',
                    'product_code' => $item->product?->product_code,
                    'quantity_per_package' => $item->quantity,
                    'quantity' => $item->quantity * $quantity,
                ])->all() ?? [],
            ]);
        }

        return array_merge($record->only([
            'id', 'reference_code', 'created_by_user_id', 'employee_id', 'current_step',
            'save_type', 'status', 'summary_label', 'total_cost', 'payload', 'note_log',
            'sales_daily_session_id', 'completed_instant_sale_id', 'completed_by_user_id',
            'cancelled_by_user_id', 'created_at', 'suspended_at', 'completed_at', 'cancelled_at',
        ]), ['lines' => $lines]);
    }

    public function record(SuspendedInstantSale $record, User $actor, string $action, ?array $before): void
    {
        // Deliberately fail the enclosing transaction if archiving fails.
        SuspendedInstantSaleRevision::create([
            'suspended_instant_sale_id' => $record->id,
            'actor_user_id' => $actor->id,
            'actor_name' => $actor->name,
            'action' => $action,
            'before_snapshot' => $before,
            'after_snapshot' => $this->snapshot($record),
        ]);
    }

    public function timeline(SuspendedInstantSale $record): array
    {
        return SuspendedInstantSaleRevision::where('suspended_instant_sale_id', $record->id)
            ->orderBy('id')->get()->toArray();
    }
}
