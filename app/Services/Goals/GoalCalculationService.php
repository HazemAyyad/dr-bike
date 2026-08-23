<?php

namespace App\Services\Goals;

use App\Enums\EmployeeTaskStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Goal;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GoalCalculationService
{
    public function calculate(Goal $goal): float
    {
        return match ($goal->type) {
            'total_sell_values' => $this->salesValue($goal),
            'sell_pieces' => $this->salesPieces($goal),
            'net_profit' => $this->netProfit($goal),
            'purchase_pieces' => $this->purchasePieces($goal),
            'total_purchase_values' => $this->purchaseValue($goal),
            'finish_tasks' => $this->completedTasks($goal),
            'pay_person' => $this->personPayments($goal),
            'deposit_to_box' => $this->boxDeposits($goal),
            default => 0.0,
        };
    }

    public function recalculate(Goal $goal): Goal
    {
        $currentValue = round($this->calculate($goal), 4);
        $targetedValue = (float) ($goal->targeted_value ?? 0);

        $goal->forceFill([
            'current_value' => $currentValue,
            'achievement_percentage' => $targetedValue > 0
                ? round(($currentValue / $targetedValue) * 100, 2)
                : 0,
        ])->save();

        return $goal->fresh();
    }

    private function salesValue(Goal $goal): float
    {
        return (float) $this->salesItemsQuery($goal)
            ->sum(DB::raw($this->netSoldQuantityExpression().' * sales_order_items.unit_price'));
    }

    private function salesPieces(Goal $goal): float
    {
        return (float) $this->salesItemsQuery($goal)
            ->sum(DB::raw($this->netSoldQuantityExpression()));
    }

    private function netProfit(Goal $goal): float
    {
        $revenue = $this->salesValue($goal);
        $query = $this->salesItemsQuery($goal)
            ->select([
                'sales_order_items.id',
                'sales_order_items.sales_order_id',
                'sales_order_items.product_id',
                'sales_order_items.quantity',
                'sales_order_items.delivered_qty',
                'sales_order_items.returned_qty',
                'products.wholesalePrice',
            ])
            ->get();

        $orderIds = $query->pluck('sales_order_id')->unique()->values();
        $orderCosts = collect();

        if ($orderIds->isNotEmpty() && Schema::hasTable('product_stock_movements')) {
            $orderCosts = DB::table('product_stock_movements')
                ->where('reference_type', 'sales_order')
                ->whereIn('reference_id', $orderIds)
                ->select('reference_id', DB::raw('ABS(SUM(COALESCE(total_cost, 0))) as total_cost'))
                ->groupBy('reference_id')
                ->pluck('total_cost', 'reference_id');
        }

        $cost = $query->groupBy('sales_order_id')->sum(function ($items, $orderId) use ($orderCosts) {
            $historicalCost = (float) ($orderCosts[$orderId] ?? 0);
            $orderNetQty = $items->sum(fn ($item) => $this->netSoldQuantityFromRow($item));

            if ($historicalCost > 0 && $orderNetQty > 0) {
                $filteredQty = $items->sum(fn ($item) => $this->netSoldQuantityFromRow($item));
                return $historicalCost * ($filteredQty / $orderNetQty);
            }

            return $items->sum(function ($item) {
                return $this->netSoldQuantityFromRow($item) * (float) ($item->wholesalePrice ?? 0);
            });
        });

        return $revenue - (float) $cost;
    }

    private function purchasePieces(Goal $goal): float
    {
        return (float) $this->purchaseReceiptItemsQuery($goal)
            ->sum('purchase_receipt_items.accepted_quantity');
    }

    private function purchaseValue(Goal $goal): float
    {
        return (float) $this->purchaseReceiptItemsQuery($goal)
            ->sum(DB::raw('purchase_receipt_items.accepted_quantity * purchase_receipt_items.unit_price'));
    }

    private function completedTasks(Goal $goal): float
    {
        [$start, $end] = $this->dateRange($goal);

        return (float) DB::table('employee_tasks')
            ->where('status', EmployeeTaskStatus::Completed->value)
            ->where(function ($query) {
                $query->whereNull('is_canceled')->orWhere('is_canceled', 0);
            })
            ->when($goal->employee_id, fn ($query) => $query->where('employee_id', $goal->employee_id))
            ->whereBetween(DB::raw('COALESCE(reviewed_at, submitted_at, updated_at, created_at)'), [$start, $end])
            ->count();
    }

    private function personPayments(Goal $goal): float
    {
        [$start, $end] = $this->dateRange($goal);

        if ($goal->form === 'employee') {
            return 0.0;
        }

        $people = DB::table('goal_people')->where('goal_id', $goal->id)->get();

        return (float) DB::table('debt_transactions')
            ->whereNull('archived_at')
            ->whereNull('deleted_at')
            ->whereBetween('transaction_date', [$start->toDateString(), $end->toDateString()])
            ->where(function ($query) use ($people) {
                foreach ($people as $person) {
                    if ($person->customer_id) {
                        $query->orWhere(function ($q) use ($person) {
                            $q->where('customer_id', $person->customer_id)
                                ->whereNull('seller_id')
                                ->where('type', 'taken');
                        });
                    }

                    if ($person->seller_id) {
                        $query->orWhere(function ($q) use ($person) {
                            $q->where('seller_id', $person->seller_id)
                                ->whereNull('customer_id')
                                ->where('type', 'given');
                        });
                    }
                }
            })
            ->sum('amount');
    }

    private function boxDeposits(Goal $goal): float
    {
        if (! $goal->box_id || ! Schema::hasTable('box_logs')) {
            return 0.0;
        }

        [$start, $end] = $this->dateRange($goal);
        $valueColumn = Schema::hasColumn('box_logs', 'value') ? 'value' : 'transfered_balance';

        $query = DB::table('box_logs')
            ->whereBetween('created_at', [$start, $end])
            ->where(function ($query) use ($goal) {
                $query->where(function ($q) use ($goal) {
                    $q->where('box_id', $goal->box_id)
                        ->whereNull('from_box_id');
                })->orWhere('to_box_id', $goal->box_id);
            });

        if (Schema::hasColumn('box_logs', 'type')) {
            $query->where(function ($query) {
                $query->whereNull('type')
                    ->orWhereNotIn('type', ['draw', 'withdraw', 'expense', 'reverse', 'reversal', 'sale_cancel']);
            });
        }

        return (float) $query->sum(DB::raw('ABS(COALESCE('.$valueColumn.', 0))'));
    }

    private function salesItemsQuery(Goal $goal): Builder
    {
        [$start, $end] = $this->dateRange($goal);

        $query = DB::table('sales_order_items')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.sales_order_id')
            ->leftJoin('products', 'products.id', '=', 'sales_order_items.product_id')
            ->where('sales_order_items.is_hidden', false)
            ->where('sales_orders.is_debt_collection', false)
            ->whereIn('sales_orders.status', $this->finalSalesStatuses())
            ->whereBetween('sales_orders.created_at', [$start, $end])
            ->whereRaw($this->netSoldQuantityExpression().' > 0');

        return $this->applyProductFilters($query, $goal, 'sales_order_items.product_id');
    }

    private function purchaseReceiptItemsQuery(Goal $goal): Builder
    {
        [$start, $end] = $this->dateRange($goal);

        $query = DB::table('purchase_receipt_items')
            ->join('purchase_receipts', 'purchase_receipts.id', '=', 'purchase_receipt_items.purchase_receipt_id')
            ->join('bills', 'bills.id', '=', 'purchase_receipts.bill_id')
            ->leftJoin('products', 'products.id', '=', 'purchase_receipt_items.product_id')
            ->where('purchase_receipt_items.accepted_quantity', '>', 0)
            ->whereNotIn('bills.workflow_status', ['cancelled', 'canceled', 'rejected'])
            ->whereNotIn('bills.status', ['cancelled', 'canceled', 'rejected'])
            ->whereBetween(DB::raw('COALESCE(purchase_receipts.received_at, purchase_receipts.created_at)'), [$start, $end]);

        if ($goal->type === 'total_purchase_values' && $goal->calculation_mode === 'detailed' && $goal->form === 'people') {
            $people = DB::table('goal_people')->where('goal_id', $goal->id)->get();
            $query->where(function ($q) use ($people) {
                foreach ($people as $person) {
                    if ($person->seller_id) {
                        $q->orWhere('bills.seller_id', $person->seller_id);
                    }
                    if ($person->customer_id) {
                        $q->orWhere('bills.customer_id', $person->customer_id);
                    }
                }
            });
        }

        return $this->applyProductFilters($query, $goal, 'purchase_receipt_items.product_id');
    }

    private function applyProductFilters(Builder $query, Goal $goal, string $productColumn): Builder
    {
        if ($goal->calculation_mode !== 'detailed') {
            return $query;
        }

        return match ($goal->form) {
            'products' => $query->whereIn($productColumn, DB::table('goal_products')
                ->where('goal_id', $goal->id)
                ->pluck('product_id')),
            'main_categories' => $query->whereIn('products.category_id', DB::table('goal_categories')
                ->where('goal_id', $goal->id)
                ->pluck('category_id')),
            'sub_categories' => $query->whereIn($productColumn, DB::table('sub_category_products')
                ->whereIn('sub_category_id', DB::table('goal_sub_categories')
                    ->where('goal_id', $goal->id)
                    ->pluck('sub_category_id'))
                ->pluck('product_id')),
            'store_sections' => $query->whereIn('products.store_section_id', DB::table('goal_store_sections')
                ->where('goal_id', $goal->id)
                ->pluck('store_section_id')),
            default => $query,
        };
    }

    private function dateRange(Goal $goal): array
    {
        $timezone = config('app.timezone');
        $start = $goal->start_date
            ? Carbon::parse($goal->start_date, $timezone)->startOfDay()
            : ($goal->created_at ? Carbon::parse($goal->created_at, $timezone)->startOfDay() : Carbon::create(1970, 1, 1, 0, 0, 0, $timezone));
        $end = $goal->due_date
            ? Carbon::parse($goal->due_date, $timezone)->endOfDay()
            : now($timezone)->endOfDay();

        return [$start, $end];
    }

    private function finalSalesStatuses(): array
    {
        return [
            SalesOrderStatus::Delivered->value,
            SalesOrderStatus::PartialDelivered->value,
            SalesOrderStatus::PartialReturn->value,
            SalesOrderStatus::Returned->value,
            SalesOrderStatus::Archived->value,
        ];
    }

    private function netSoldQuantityExpression(): string
    {
        return 'GREATEST(COALESCE(sales_order_items.delivered_qty, sales_order_items.quantity) - COALESCE(sales_order_items.returned_qty, 0), 0)';
    }

    private function netSoldQuantityFromRow(object $row): float
    {
        return max(0, (float) ($row->delivered_qty ?? $row->quantity ?? 0) - (float) ($row->returned_qty ?? 0));
    }
}
