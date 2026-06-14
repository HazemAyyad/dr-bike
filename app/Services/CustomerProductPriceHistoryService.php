<?php

namespace App\Services;

use App\Models\InstantSale;
use Illuminate\Database\Eloquent\Builder;

class CustomerProductPriceHistoryService
{
    /**
     * @return array{last_price: float|null, entries: list<array{cost: float, invoice_id: int, sold_at: string}>}
     */
    public function getHistory(
        string $personType,
        int $personId,
        int $productId,
        ?int $sizeColorId = null,
        int $limit = 5
    ): array {
        $limit = max(1, min($limit, 20));

        $rows = $this->baseQuery($personType, $personId, $productId, $sizeColorId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'parent_id', 'cost', 'created_at']);

        $entries = $rows
            ->map(fn (InstantSale $sale) => $this->formatEntry($sale))
            ->values()
            ->all();

        $lastPrice = $entries[0]['cost'] ?? null;

        return [
            'last_price' => $lastPrice,
            'entries' => $entries,
        ];
    }

    private function baseQuery(
        string $personType,
        int $personId,
        int $productId,
        ?int $sizeColorId
    ): Builder {
        $query = InstantSale::query()
            ->where('product_id', $productId)
            ->where('cost', '>', 0)
            ->where(function (Builder $q) {
                $q->whereNull('status')
                    ->orWhere('status', '!=', 'cancelled');
            })
            ->whereNull('cancelled_at');

        if ($sizeColorId !== null && $sizeColorId > 0) {
            $query->where('size_color_id', $sizeColorId);
        } else {
            $query->where(function (Builder $q) {
                $q->whereNull('size_color_id')
                    ->orWhere('size_color_id', 0);
            });
        }

        $query->where(function (Builder $q) use ($personType, $personId) {
            $q->where(function (Builder $root) use ($personType, $personId) {
                $root->whereNull('parent_id');
                $this->applyPersonFilter($root, $personType, $personId, 'instant_sales');
            })->orWhere(function (Builder $sub) use ($personType, $personId) {
                $sub->whereNotNull('parent_id')
                    ->whereExists(function ($exists) use ($personType, $personId) {
                        $exists->selectRaw('1')
                            ->from('instant_sales as parent_sales')
                            ->whereColumn('parent_sales.id', 'instant_sales.parent_id')
                            ->whereNull('parent_sales.parent_id');
                        $this->applyPersonFilter($exists, $personType, $personId, 'parent_sales');
                    });
            });
        });

        return $query;
    }

    private function applyPersonFilter($query, string $personType, int $personId, string $table): void
    {
        if ($personType === 'customer') {
            $query->where("{$table}.buyer_id", $personId)
                ->where("{$table}.buyer_type", 'customer');
        } else {
            $query->where("{$table}.seller_id", $personId);
        }
    }

    /**
     * @return array{cost: float, invoice_id: int, sold_at: string}
     */
    private function formatEntry(InstantSale $sale): array
    {
        return [
            'cost' => round((float) $sale->cost, 2),
            'invoice_id' => (int) ($sale->parent_id ?: $sale->id),
            'sold_at' => $sale->created_at?->format('Y-m-d H:i:s') ?? '',
        ];
    }
}
