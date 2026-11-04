<?php

namespace App\Services;

use App\Models\InstantSale;
use App\Models\OfferPackage;
use App\Models\Product;
use App\Models\ProductStockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductSaleStatusService
{
    public function setBlocked(int $productId, bool $blocked, ?int $userId): Product
    {
        return DB::transaction(function () use ($productId, $blocked, $userId) {
            $product = Product::query()->lockForUpdate()->findOrFail($productId);
            if ((bool) $product->is_sale_blocked !== $blocked) {
                $product->update(['is_sale_blocked' => $blocked]);
                app(ProductStockService::class)->logMovement(
                    productId: $productId,
                    sizeId: null,
                    sizeColorId: null,
                    type: ProductStockMovement::TYPE_PRODUCT_UPDATE,
                    quantity: 0,
                    stockBefore: (int) $product->stock,
                    stockAfter: (int) $product->stock,
                    referenceType: 'product_sale_status',
                    referenceId: $productId,
                    note: $blocked ? 'تم منع المنتج من البيع' : 'تم السماح ببيع المنتج',
                    userId: $userId,
                );
            }

            return $product;
        });
    }

    /**
     * Existing identities may be retained or reduced during invoice edits.
     * New identities and quantity increases require a sellable product.
     */
    public function assertLinesAllowed(iterable $lines, iterable $existing = []): void
    {
        $sum = static function (iterable $rows): array {
            $totals = [];
            foreach ($rows as $row) {
                $id = (int) data_get($row, 'product_id');
                if ($id <= 0) {
                    continue;
                }
                $identity = $id.':'.((int) data_get($row, 'size_color_id') ?: 'main');
                $totals[$identity] = ($totals[$identity] ?? 0) + (float) data_get($row, 'quantity', 0);
            }

            return $totals;
        };
        $requested = $sum($lines);
        $previous = $sum($existing);
        $ids = [];
        foreach ($requested as $identity => $quantity) {
            if ($quantity > ($previous[$identity] ?? 0) + 0.0001) {
                $ids[] = (int) explode(':', $identity)[0];
            }
        }
        if ($ids === []) {
            return;
        }

        // Use the same product lock as stock changes and the status toggle.
        $products = Product::query()->whereIn('id', array_unique($ids))
            ->orderBy('id')->lockForUpdate()->get();
        foreach ($products as $product) {
            if ($product->is_sale_blocked) {
                throw ValidationException::withMessages([
                    'product_id' => ['المنتج «'.$product->nameAr.'» ممنوع من البيع.'],
                ]);
            }
        }
    }

    public function assertInstantSaleAllowed(array $data, int $replacementId = 0): void
    {
        $lines = $data['other_products'] ?? [];
        if (! empty($data['offer_package_id'])) {
            $package = OfferPackage::with('items')->findOrFail($data['offer_package_id']);
            foreach ($package->items as $item) {
                $lines[] = ['product_id' => $item->product_id, 'quantity' => $item->quantity * $data['quantity']];
            }
        } else {
            $lines[] = $data;
        }
        $existing = $replacementId > 0
            ? InstantSale::query()->where(fn ($query) => $query->where('id', $replacementId)->orWhere('parent_id', $replacementId))->get()
            : [];
        $this->assertLinesAllowed($lines, $existing);
    }
}
