<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Seller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PersonProfileService
{
    public function getProfile(string $personType, int $personId): array
    {
        $person = $this->person($personType, $personId);
        $lines = $this->purchaseLines($personType, $personId);
        $invoices = $this->recentInvoices($lines);
        $products = $this->purchasedProducts($lines);
        $topProducts = $products
            ->sortByDesc('quantity')
            ->take(5)
            ->values()
            ->all();
        $debt = $this->debtSummary($personType, $personId);

        return [
            'person' => $person,
            'summary' => [
                'invoice_count' => $lines->pluck('invoice_key')->unique()->count(),
                'distinct_products_count' => $products->count(),
                'total_quantity' => round((float) $lines->sum('quantity'), 2),
                'total_paid' => round((float) $lines->sum('line_total'), 2),
                'debt_owed_to_us' => $debt['owed_to_us'],
                'debt_we_owe' => $debt['we_owe'],
                'debt_balance' => $debt['balance'],
                'last_purchase_at' => $lines->max('sold_at'),
                'average_invoice_total' => $invoices->isEmpty()
                    ? 0
                    : round((float) $invoices->avg('total'), 2),
            ],
            'recent_invoices' => $invoices->take(5)->values()->all(),
            'top_products' => $topProducts,
            'purchased_products' => $products->values()->all(),
        ];
    }

    public function getProductHistory(
        string $personType,
        int $personId,
        int $productId,
        ?int $sizeColorId = null
    ): array {
        $this->person($personType, $personId);

        $entries = $this->purchaseLines($personType, $personId)
            ->filter(function (array $line) use ($productId, $sizeColorId) {
                if ((int) $line['product_id'] !== $productId) {
                    return false;
                }

                if ($sizeColorId !== null && $sizeColorId > 0) {
                    return (int) ($line['size_color_id'] ?? 0) === $sizeColorId;
                }

                return true;
            })
            ->sortByDesc('sold_at')
            ->values()
            ->map(fn (array $line) => [
                'cost' => round((float) $line['unit_price'], 2),
                'quantity' => round((float) $line['quantity'], 2),
                'line_total' => round((float) $line['line_total'], 2),
                'invoice_id' => (int) $line['invoice_id'],
                'invoice_number' => $line['invoice_number'],
                'invoice_type' => $line['invoice_type'],
                'sold_at' => $line['sold_at'],
            ])
            ->all();

        return [
            'last_price' => $entries[0]['cost'] ?? null,
            'entries' => $entries,
        ];
    }

    private function person(string $personType, int $personId): array
    {
        if (! in_array($personType, ['customer', 'seller'], true)) {
            throw ValidationException::withMessages([
                'person_type' => ['person_type must be customer or seller.'],
            ]);
        }

        $model = $personType === 'customer'
            ? Customer::query()->findOrFail($personId)
            : Seller::query()->findOrFail($personId);

        return [
            'id' => (int) $model->id,
            'type' => $personType,
            'name' => (string) ($model->name ?? ''),
            'phone' => (string) ($model->phone ?? ''),
            'sub_phone' => (string) ($model->sub_phone ?? ''),
            'job_title' => (string) ($model->job_title ?? ''),
            'address' => (string) ($model->address ?? ''),
            'category' => (string) ($model->type ?? ''),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function purchaseLines(string $personType, int $personId): Collection
    {
        $instantRows = $this->instantSaleLines($personType, $personId);
        $orderRows = $personType === 'customer'
            ? $this->salesOrderLines($personId)
            : collect();

        return $instantRows
            ->concat($orderRows)
            ->sortByDesc('sold_at')
            ->values();
    }

    private function instantSaleLines(string $personType, int $personId): Collection
    {
        $query = DB::table('instant_sales')
            ->leftJoin('instant_sales as parent_sales', 'parent_sales.id', '=', 'instant_sales.parent_id')
            ->leftJoin('products', 'products.id', '=', 'instant_sales.product_id')
            ->whereNotNull('instant_sales.product_id')
            ->whereNull('instant_sales.sales_order_id')
            ->where('instant_sales.cost', '>', 0)
            ->whereNull('instant_sales.cancelled_at')
            ->where(function ($q) {
                $q->whereNull('instant_sales.status')
                    ->orWhere('instant_sales.status', '!=', 'cancelled');
            });

        $query->where(function ($q) use ($personType, $personId) {
            $this->applyInstantPersonFilter($q, $personType, $personId, 'instant_sales');
            $q->orWhere(function ($sub) use ($personType, $personId) {
                $sub->whereNotNull('instant_sales.parent_id');
                $this->applyInstantPersonFilter($sub, $personType, $personId, 'parent_sales');
            });
        });

        return $query
            ->select([
                'instant_sales.id',
                'instant_sales.parent_id',
                'instant_sales.product_id',
                'instant_sales.size_color_id',
                'instant_sales.cost',
                'instant_sales.quantity',
                'instant_sales.total_cost',
                'instant_sales.created_at',
                'instant_sales.serial_number',
                'parent_sales.serial_number as parent_serial_number',
                'products.nameAr as product_name',
                'products.product_code',
            ])
            ->get()
            ->map(fn ($row) => [
                'invoice_type' => 'instant_sale',
                'invoice_id' => (int) ($row->parent_id ?: $row->id),
                'invoice_number' => $row->parent_serial_number ?: $row->serial_number ?: (string) ($row->parent_id ?: $row->id),
                'invoice_key' => 'instant_sale:'.($row->parent_id ?: $row->id),
                'product_id' => (int) $row->product_id,
                'size_color_id' => $row->size_color_id === null ? null : (int) $row->size_color_id,
                'product_name' => (string) ($row->product_name ?? ''),
                'product_code' => (string) ($row->product_code ?? ''),
                'quantity' => (float) ($row->quantity ?? 0),
                'unit_price' => (float) ($row->cost ?? 0),
                'line_total' => (float) (($row->total_cost ?? 0) ?: ((float) $row->cost * (float) $row->quantity)),
                'sold_at' => (string) $row->created_at,
            ]);
    }

    private function salesOrderLines(int $customerId): Collection
    {
        return DB::table('sales_order_items')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.sales_order_id')
            ->leftJoin('products', 'products.id', '=', 'sales_order_items.product_id')
            ->where('sales_orders.customer_id', $customerId)
            ->where('sales_orders.is_debt_collection', false)
            ->where('sales_orders.status', '!=', 'canceled')
            ->where('sales_order_items.is_hidden', false)
            ->where('sales_order_items.unit_price', '>', 0)
            ->select([
                'sales_orders.id as invoice_id',
                'sales_orders.serial_number',
                'sales_orders.created_at',
                'sales_order_items.product_id',
                'sales_order_items.size_color_id',
                'sales_order_items.product_name',
                'sales_order_items.quantity',
                'sales_order_items.unit_price',
                'sales_order_items.line_total',
                'products.nameAr as product_name_ar',
                'products.product_code',
            ])
            ->get()
            ->map(fn ($row) => [
                'invoice_type' => 'sales_order',
                'invoice_id' => (int) $row->invoice_id,
                'invoice_number' => $row->serial_number ?: (string) $row->invoice_id,
                'invoice_key' => 'sales_order:'.$row->invoice_id,
                'product_id' => (int) $row->product_id,
                'size_color_id' => $row->size_color_id === null ? null : (int) $row->size_color_id,
                'product_name' => (string) ($row->product_name ?: $row->product_name_ar ?: ''),
                'product_code' => (string) ($row->product_code ?? ''),
                'quantity' => (float) ($row->quantity ?? 0),
                'unit_price' => (float) ($row->unit_price ?? 0),
                'line_total' => (float) (($row->line_total ?? 0) ?: ((float) $row->unit_price * (float) $row->quantity)),
                'sold_at' => (string) $row->created_at,
            ]);
    }

    private function purchasedProducts(Collection $lines): Collection
    {
        return $lines
            ->groupBy('product_id')
            ->map(function (Collection $rows) {
                $prices = $rows->pluck('unit_price')->map(fn ($v) => (float) $v);
                $latest = $rows->sortByDesc('sold_at')->first();

                return [
                    'product_id' => (int) $latest['product_id'],
                    'product_name' => $latest['product_name'],
                    'product_code' => $latest['product_code'],
                    'purchase_count' => $rows->count(),
                    'quantity' => round((float) $rows->sum('quantity'), 2),
                    'total_paid' => round((float) $rows->sum('line_total'), 2),
                    'last_price' => round((float) $latest['unit_price'], 2),
                    'min_price' => round((float) $prices->min(), 2),
                    'max_price' => round((float) $prices->max(), 2),
                    'last_purchase_at' => $latest['sold_at'],
                ];
            })
            ->sortByDesc('last_purchase_at');
    }

    private function recentInvoices(Collection $lines): Collection
    {
        return $lines
            ->groupBy('invoice_key')
            ->map(function (Collection $rows) {
                $latest = $rows->sortByDesc('sold_at')->first();

                return [
                    'invoice_type' => $latest['invoice_type'],
                    'invoice_id' => (int) $latest['invoice_id'],
                    'invoice_number' => $latest['invoice_number'],
                    'sold_at' => $latest['sold_at'],
                    'items_count' => $rows->count(),
                    'quantity' => round((float) $rows->sum('quantity'), 2),
                    'total' => round((float) $rows->sum('line_total'), 2),
                ];
            })
            ->sortByDesc('sold_at')
            ->values();
    }

    private function debtSummary(string $personType, int $personId): array
    {
        $query = DB::table('debt_transactions')
            ->whereNull('archived_at')
            ->whereNull('deleted_at');

        if ($personType === 'customer') {
            $query->where('customer_id', $personId)->whereNull('seller_id');
        } else {
            $query->where('seller_id', $personId)->whereNull('customer_id');
        }

        $rows = $query->get(['type', 'amount']);
        $owedToUs = (float) $rows->where('type', 'taken')->sum('amount');
        $weOwe = (float) $rows->where('type', 'given')->sum('amount');

        return [
            'owed_to_us' => round($owedToUs, 2),
            'we_owe' => round($weOwe, 2),
            'balance' => round($owedToUs - $weOwe, 2),
        ];
    }

    private function applyInstantPersonFilter($query, string $personType, int $personId, string $table): void
    {
        if ($personType === 'customer') {
            $query->where("{$table}.buyer_id", $personId)
                ->where("{$table}.buyer_type", 'customer');
            return;
        }

        $query->where("{$table}.seller_id", $personId);
    }
}
