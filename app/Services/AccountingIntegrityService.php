<?php

namespace App\Services;

use App\Models\AccountingJournalEntry;
use App\Models\Asset;
use App\Models\AssetLog;
use App\Models\BoxLog;
use App\Models\Expense;
use App\Models\InstantSale;
use App\Models\Maintenance;
use App\Models\MaintenancePayment;
use App\Models\ProfitSale;
use App\Models\PurchasePayment;
use App\Models\PurchaseReceipt;
use App\Models\ReturnModel;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AccountingIntegrityService
{
    public function __construct(
        private InventoryCostIntegrityService $inventoryIntegrity,
        private AssetDepreciationCalculator $assetDepreciation,
    ) {}

    /** @return array{checks:array<int,array<string,mixed>>,summary:array<string,int>} */
    public function run(?Carbon $from = null, ?Carbon $to = null): array
    {
        $from ??= Carbon::parse('1900-01-01')->startOfDay();
        $to ??= now()->endOfDay();
        $checks = [];

        $checks[] = $this->checkJournalBalance($from, $to);
        $checks[] = $this->checkProductSales($from, $to);
        $checks[] = $this->checkProfitSales($from, $to);
        $checks[] = $this->checkPurchases($from, $to);
        $checks[] = $this->checkPurchaseReturns($from, $to);
        $checks[] = $this->checkSalesReturns($from, $to);
        $checks[] = $this->checkMaintenance($from, $to);
        $checks[] = $this->checkMaintenancePrepayments($from, $to);
        $checks[] = $this->checkExpenses($from, $to);
        $checks[] = $this->checkAssets($from, $to);
        $checks[] = $this->checkBoxes($from, $to);
        $checks[] = $this->checkPartyDimensions($from, $to);
        $checks[] = $this->checkInventoryLayersAndAllocations();
        $checks[] = $this->checkProjectionFailures($from, $to);

        return [
            'checks' => $checks,
            'summary' => [
                'pass' => collect($checks)->where('status', 'PASS')->count(),
                'warning' => collect($checks)->where('status', 'WARNING')->count(),
                'error' => collect($checks)->where('status', 'ERROR')->count(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function checkJournalBalance(Carbon $from, Carbon $to): array
    {
        $ids = DB::table('accounting_journal_entries as entries')
            ->leftJoin('accounting_journal_lines as lines', 'lines.journal_entry_id', '=', 'entries.id')
            ->whereDate('entries.entry_date', '>=', $from->toDateString())
            ->whereDate('entries.entry_date', '<=', $to->toDateString())
            ->groupBy('entries.id')
            ->havingRaw('COUNT(lines.id) < 2 OR ABS(COALESCE(SUM(lines.debit), 0) - COALESCE(SUM(lines.credit), 0)) > 0.0001')
            ->pluck('entries.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $this->check('journal_balance', $ids === [] ? 'PASS' : 'ERROR', 'Every journal entry must balance and contain at least two lines.', $ids);
    }

    /** @return array<string, mixed> */
    private function checkProductSales(Carbon $from, Carbon $to): array
    {
        $errors = [];
        $warnings = [];
        $query = InstantSale::query()
            ->whereNull('parent_id')
            ->whereBetween('created_at', [$from, $to])
            ->where(fn (Builder $query) => $query->whereNull('status')->orWhere('status', '!=', 'cancelled'))
            ->whereNull('cancelled_at')
            ->where(fn (Builder $query) => $query->whereNull('sale_kind')->orWhere('sale_kind', 'regular'));

        $query->with('subProducts')->orderBy('id')->chunkById(200, function ($sales) use (&$errors, &$warnings) {
            foreach ($sales as $sale) {
                $accounts = $this->journalAccounts('instant_sale', (int) $sale->id);
                $revenue = $sale->maintenance_id ? 'maintenance_revenue' : 'sales_revenue';
                $inspection = $this->inventoryIntegrity->inspectInstantSale($sale);
                if (! $accounts->contains($revenue) || ! $inspection['ready']) {
                    $errors[] = (int) $sale->id;

                    continue;
                }
                if ($inspection['requires_cost'] && (float) $inspection['total_cost'] > 0.0001
                    && (! $accounts->contains('cost_of_goods_sold') || ! $accounts->contains('inventory'))) {
                    $errors[] = (int) $sale->id;
                } elseif ($inspection['requires_cost'] && (float) $inspection['total_cost'] <= 0.0001) {
                    $warnings[] = (int) $sale->id;
                }
            }
        });

        SalesOrder::query()
            ->where('is_debt_collection', false)
            ->whereNotNull('financial_posted_at')
            ->where('status', '!=', 'canceled')
            ->whereBetween('financial_posted_at', [$from, $to])
            ->with('items')
            ->orderBy('id')
            ->chunkById(200, function ($orders) use (&$errors, &$warnings) {
                foreach ($orders as $order) {
                    $hasInstantSale = (bool) $order->instant_sale_id
                        || InstantSale::query()
                            ->where('sales_order_id', $order->id)
                            ->whereNull('parent_id')
                            ->exists();
                    if ($hasInstantSale) {
                        continue;
                    }
                    $accounts = $this->journalAccounts('sales_order', (int) $order->id);
                    $inspection = $this->inventoryIntegrity->inspectSalesOrder($order);
                    if (! $accounts->contains('sales_revenue') || ! $inspection['ready']) {
                        $errors[] = 'sales_order:'.$order->id;

                        continue;
                    }
                    if ($inspection['requires_cost'] && (float) $inspection['total_cost'] > 0.0001
                        && (! $accounts->contains('cost_of_goods_sold') || ! $accounts->contains('inventory'))) {
                        $errors[] = 'sales_order:'.$order->id;
                    } elseif ($inspection['requires_cost'] && (float) $inspection['total_cost'] <= 0.0001) {
                        $warnings[] = 'sales_order:'.$order->id;
                    }
                }
            });

        return $this->check(
            'product_sales',
            $errors !== [] ? 'ERROR' : ($warnings !== [] ? 'WARNING' : 'PASS'),
            'Product sales require revenue plus reliable FIFO-backed COGS and inventory effects.',
            array_values(array_unique(array_merge($errors, $warnings))),
            ['errors' => array_values(array_unique($errors)), 'zero_cost_warnings' => array_values(array_unique($warnings))],
        );
    }

    /** @return array<string, mixed> */
    private function checkProfitSales(Carbon $from, Carbon $to): array
    {
        $errors = [];
        $legacy = [];
        ProfitSale::query()
            ->whereBetween('created_at', [$from, $to])
            ->where(fn (Builder $query) => $query->whereNull('status')->orWhere('status', '!=', 'cancelled'))
            ->whereNull('cancelled_at')
            ->orderBy('id')
            ->chunkById(200, function ($sales) use (&$errors, &$legacy) {
                foreach ($sales as $sale) {
                    $accounts = $this->journalAccounts('profit_sale', (int) $sale->id);
                    if ((! $accounts->contains('service_revenue') && ! $accounts->contains('other_revenue'))
                        || $accounts->contains('inventory') || $accounts->contains('cost_of_goods_sold')) {
                        $errors[] = (int) $sale->id;
                    } elseif ($accounts->contains('other_revenue')) {
                        $legacy[] = (int) $sale->id;
                    }
                }
            });

        return $this->check(
            'profit_sales_service_revenue',
            $errors !== [] ? 'ERROR' : ($legacy !== [] ? 'WARNING' : 'PASS'),
            'ProfitSale is service revenue only; legacy other_revenue journals are reported without rewriting history.',
            array_values(array_unique(array_merge($errors, $legacy))),
            ['errors' => $errors, 'legacy_other_revenue' => $legacy],
        );
    }

    /** @return array<string, mixed> */
    private function checkPurchases(Carbon $from, Carbon $to): array
    {
        $errors = [];
        PurchaseReceipt::query()->whereBetween('created_at', [$from, $to])->with(['items', 'bill'])->orderBy('id')->chunkById(200, function ($receipts) use (&$errors) {
            foreach ($receipts as $receipt) {
                $amount = (float) $receipt->items->sum(fn ($item) => (float) $item->accepted_quantity * (float) $item->unit_price);
                if ($amount <= 0.0001) {
                    continue;
                }
                $accounts = $this->journalAccounts('purchase_receipt', (int) $receipt->id);
                $partyLinked = $this->journalPartyLinked(
                    'purchase_receipt',
                    (int) $receipt->id,
                    $receipt->bill?->customer_id,
                    $receipt->bill?->seller_id,
                );
                if (! $accounts->contains('inventory')
                    || ! $accounts->intersect(['accounts_payable', 'accounts_receivable'])->count()
                    || ! $partyLinked) {
                    $errors[] = 'receipt:'.$receipt->id;
                }
            }
        });
        PurchasePayment::query()->whereBetween('created_at', [$from, $to])->orderBy('id')->chunkById(200, function ($payments) use (&$errors) {
            foreach ($payments as $payment) {
                if ((float) $payment->amount <= 0.0001) {
                    continue;
                }
                $accounts = $this->journalAccounts('purchase_payment', (int) $payment->id);
                $partyLinked = $this->journalPartyLinked(
                    'purchase_payment',
                    (int) $payment->id,
                    $payment->customer_id,
                    $payment->seller_id,
                );
                $boxLinked = ! $payment->box_id || DB::table('accounting_journal_entries as entries')
                    ->join('accounting_journal_lines as lines', 'lines.journal_entry_id', '=', 'entries.id')
                    ->join('accounting_accounts as accounts', 'accounts.id', '=', 'lines.account_id')
                    ->where('entries.source_type', 'purchase_payment')
                    ->where('entries.source_id', $payment->id)
                    ->where('entries.status', AccountingJournalEntry::STATUS_POSTED)
                    ->whereNull('entries.reverses_entry_id')
                    ->where('accounts.system_key', 'cash')
                    ->where('lines.box_id', $payment->box_id)
                    ->exists();
                if (! $accounts->intersect(['cash', 'clearing'])->count()
                    || ! $accounts->intersect(['accounts_payable', 'accounts_receivable'])->count()
                    || (! $payment->seller_id && ! $payment->customer_id)
                    || ! $partyLinked
                    || ! $boxLinked) {
                    $errors[] = 'payment:'.$payment->id;
                }
            }
        });

        return $this->check('purchases', $errors === [] ? 'PASS' : 'ERROR', 'Receipts must debit inventory and payments must connect the supplier/customer account to cash or clearing.', $errors);
    }

    /** @return array<string, mixed> */
    private function checkPurchaseReturns(Carbon $from, Carbon $to): array
    {
        $errors = [];
        ReturnModel::query()->whereBetween('created_at', [$from, $to])
            ->whereIn('status', ['delivered', 'settled'])->whereNull('cancelled_at')
            ->orderBy('id')->chunkById(200, function ($returns) use (&$errors) {
                foreach ($returns as $return) {
                    $accounts = $this->journalAccounts('purchase_return', (int) $return->id);
                    $inventory = $this->inventoryIntegrity->inspectPurchaseReturn($return);
                    if (! $inventory['ready']
                        || ! $accounts->contains('inventory')
                        || ! $accounts->intersect(['cash', 'accounts_payable', 'accounts_receivable', 'clearing'])->count()) {
                        $errors[] = (int) $return->id;
                    }
                }
            });

        return $this->check('purchase_returns', $errors === [] ? 'PASS' : 'ERROR', 'Completed purchase returns must reverse inventory and the supplier/cash effect.', $errors);
    }

    /** @return array<string, mixed> */
    private function checkSalesReturns(Carbon $from, Carbon $to): array
    {
        $errors = [];
        SalesReturn::query()->whereBetween('created_at', [$from, $to])
            ->whereIn('return_type', ['direct', 'partial'])->where('status', 'completed')->whereNull('cancelled_at')
            ->with('items')->orderBy('id')->chunkById(200, function ($returns) use (&$errors) {
                foreach ($returns as $return) {
                    $accounts = $this->journalAccounts('sales_return', (int) $return->id);
                    $cost = (float) $return->items->sum('inventory_total_cost');
                    $missingCost = $return->items->contains(fn ($item) => (float) $item->quantity > 0 && $item->inventory_total_cost === null);
                    if ($missingCost || ! $accounts->contains('sales_returns')
                        || ($cost > 0.0001 && (! $accounts->contains('inventory') || ! $accounts->contains('cost_of_goods_sold')))) {
                        $errors[] = (int) $return->id;
                    }
                }
            });

        return $this->check('sales_returns', $errors === [] ? 'PASS' : 'ERROR', 'Completed sales returns must reverse revenue and original inventory cost.', $errors);
    }

    /** @return array<string, mixed> */
    private function checkMaintenance(Carbon $from, Carbon $to): array
    {
        $errors = [];
        Maintenance::query()->whereBetween('created_at', [$from, $to])->where('status', 'delivered')
            ->with('products')->orderBy('id')->chunkById(200, function ($rows) use (&$errors) {
                foreach ($rows as $maintenance) {
                    if ((float) $maintenance->invoice_total <= 0 && $maintenance->products->isEmpty()) {
                        continue;
                    }
                    if (! $maintenance->instant_sale_id
                        || $maintenance->products->contains(fn ($part) => (float) $part->quantity > 0 && $part->inventory_total_cost === null)
                        || ! $this->journalAccounts('instant_sale', (int) $maintenance->instant_sale_id)->contains('maintenance_revenue')) {
                        $errors[] = (int) $maintenance->id;
                    }
                }
            });

        return $this->check('maintenance', $errors === [] ? 'PASS' : 'ERROR', 'Delivered maintenance requires an invoice journal and FIFO cost for every used part.', $errors);
    }

    /** @return array<string, mixed> */
    private function checkMaintenancePrepayments(Carbon $from, Carbon $to): array
    {
        if (! Schema::hasTable('maintenance_payments')
            || ! Schema::hasColumn('maintenance_payments', 'payment_stage')) {
            return $this->check(
                'maintenance_prepayments',
                'WARNING',
                'Maintenance payment stages are not available until the additive migration is applied.',
                [],
            );
        }

        $errors = [];
        $warnings = [];

        MaintenancePayment::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('payment_stage', MaintenancePayment::STAGE_PRE_DELIVERY)
            ->whereRaw('ABS(amount) > 0.0001')
            ->with('maintenance')
            ->orderBy('id')
            ->chunkById(200, function ($payments) use (&$errors) {
                foreach ($payments as $payment) {
                    $lines = DB::table('accounting_journal_entries as entries')
                        ->join('accounting_journal_lines as lines', 'lines.journal_entry_id', '=', 'entries.id')
                        ->join('accounting_accounts as accounts', 'accounts.id', '=', 'lines.account_id')
                        ->where('entries.source_type', 'maintenance_payment')
                        ->where('entries.source_id', $payment->id)
                        ->where('entries.status', AccountingJournalEntry::STATUS_POSTED)
                        ->whereNull('entries.reverses_entry_id')
                        ->get(['accounts.system_key', 'lines.debit', 'lines.credit', 'lines.box_id']);
                    $accounts = $lines->pluck('system_key')->unique();
                    $cashBoxMatches = $lines->contains(fn ($line) => $line->system_key === 'cash'
                        && (int) $line->box_id === (int) $payment->box_id);

                    if (! $accounts->contains('cash')
                        || ! $accounts->contains('customer_deposits')
                        || ! $cashBoxMatches) {
                        $errors[] = 'payment:'.$payment->id;
                    }
                }
            });

        $deliveryDuplicates = DB::table('maintenance_payments as payments')
            ->join('accounting_journal_entries as entries', function ($join) {
                $join->on('entries.source_id', '=', 'payments.id')
                    ->where('entries.source_type', 'maintenance_payment');
            })
            ->whereBetween('payments.created_at', [$from, $to])
            ->where('payments.payment_stage', MaintenancePayment::STAGE_DELIVERY)
            ->where('entries.status', AccountingJournalEntry::STATUS_POSTED)
            ->whereNull('entries.reverses_entry_id')
            ->pluck('payments.id')
            ->map(fn ($id) => 'delivery_double_post:'.$id)
            ->all();
        $errors = array_merge($errors, $deliveryDuplicates);

        Maintenance::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('status', 'delivered')
            ->whereNotNull('instant_sale_id')
            ->with(['payments', 'instantSale'])
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$errors, &$warnings) {
                foreach ($rows as $maintenance) {
                    $typed = $maintenance->payments->whereNotNull('payment_stage');
                    if ($typed->isEmpty()) {
                        if ($maintenance->payments->isNotEmpty()) {
                            $warnings[] = 'legacy_payment_stage:'.$maintenance->id;
                        }

                        continue;
                    }

                    $prepaid = round((float) $typed
                        ->where('payment_stage', MaintenancePayment::STAGE_PRE_DELIVERY)
                        ->sum('amount'), 4);
                    $deliveryPaid = round((float) $typed
                        ->where('payment_stage', MaintenancePayment::STAGE_DELIVERY)
                        ->sum('amount'), 4);
                    $invoiceTotal = round((float) $maintenance->invoice_total, 4);
                    $paid = round($prepaid + $deliveryPaid, 4);
                    if ($prepaid - $invoiceTotal > 0.0001
                        || abs($paid - (float) ($maintenance->instantSale?->payment_box_value ?? 0)) > 0.01) {
                        $errors[] = 'settlement:'.$maintenance->id;

                        continue;
                    }

                    $journalLines = DB::table('accounting_journal_entries as entries')
                        ->join('accounting_journal_lines as lines', 'lines.journal_entry_id', '=', 'entries.id')
                        ->join('accounting_accounts as accounts', 'accounts.id', '=', 'lines.account_id')
                        ->where('entries.source_type', 'instant_sale')
                        ->where('entries.source_id', $maintenance->instant_sale_id)
                        ->where('entries.status', AccountingJournalEntry::STATUS_POSTED)
                        ->whereNull('entries.reverses_entry_id')
                        ->get(['accounts.system_key', 'lines.debit', 'lines.credit']);
                    $depositReleased = (float) $journalLines
                        ->where('system_key', 'customer_deposits')
                        ->sum(fn ($line) => (float) $line->debit - (float) $line->credit);
                    $cashAtDelivery = (float) $journalLines
                        ->where('system_key', 'cash')
                        ->sum(fn ($line) => (float) $line->debit - (float) $line->credit);
                    $revenue = (float) $journalLines
                        ->where('system_key', 'maintenance_revenue')
                        ->sum(fn ($line) => (float) $line->credit - (float) $line->debit);

                    if (abs($depositReleased - $prepaid) > 0.01
                        || abs($cashAtDelivery - $deliveryPaid) > 0.01
                        || abs($revenue - $invoiceTotal) > 0.01) {
                        $errors[] = 'invoice_release:'.$maintenance->id;
                    }

                    if ($maintenance->customer_id) {
                        $receivable = (float) $journalLines
                            ->where('system_key', 'accounts_receivable')
                            ->sum(fn ($line) => (float) $line->debit - (float) $line->credit);
                        if (abs(($paid + max(0, $receivable)) - $invoiceTotal) > 0.01) {
                            $errors[] = 'invoice_balance:'.$maintenance->id;
                        }
                    }
                }
            });

        $legacyIds = MaintenancePayment::query()
            ->whereBetween('created_at', [$from, $to])
            ->whereNull('payment_stage')
            ->pluck('id')
            ->map(fn ($id) => 'legacy_payment:'.$id)
            ->all();
        $warnings = array_merge($warnings, $legacyIds);

        $balanceIssues = $this->maintenanceDepositBalanceIssues();
        $errors = array_values(array_unique(array_merge($errors, $balanceIssues)));
        $warnings = array_values(array_unique($warnings));

        return $this->check(
            'maintenance_prepayments',
            $errors !== [] ? 'ERROR' : ($warnings !== [] ? 'WARNING' : 'PASS'),
            'Maintenance prepayments require cash/customer-deposit journals and must be released exactly once at delivery.',
            array_values(array_unique(array_merge($errors, $warnings))),
            ['errors' => $errors, 'legacy_warnings' => $warnings],
        );
    }

    /** @return array<int, string> */
    private function maintenanceDepositBalanceIssues(): array
    {
        $operational = DB::table('maintenance_payments as payments')
            ->join('maintenance as maintenance', 'maintenance.id', '=', 'payments.maintenance_id')
            ->where('payments.payment_stage', MaintenancePayment::STAGE_PRE_DELIVERY)
            ->whereNull('maintenance.instant_sale_id')
            ->where('maintenance.status', '!=', 'delivered')
            ->when(
                Schema::hasColumn('maintenance', 'deleted_at'),
                fn ($query) => $query->whereNull('maintenance.deleted_at'),
            )
            ->selectRaw("COALESCE(NULLIF(payments.currency, ''), 'شيكل') as currency, SUM(payments.amount) as amount")
            ->groupBy('payments.currency')
            ->get()
            ->keyBy(fn ($row) => $this->normalizeCurrency($row->currency));

        $ledger = DB::table('accounting_journal_lines as lines')
            ->join('accounting_journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->join('accounting_accounts as accounts', 'accounts.id', '=', 'lines.account_id')
            ->leftJoin('instant_sales as sales', function ($join) {
                $join->on('sales.id', '=', 'entries.source_id')
                    ->where('entries.source_type', 'instant_sale');
            })
            ->where('accounts.system_key', 'customer_deposits')
            ->where('entries.status', AccountingJournalEntry::STATUS_POSTED)
            ->where(function ($query) {
                $query->where('entries.source_type', 'maintenance_payment')
                    ->orWhere(fn ($nested) => $nested
                        ->where('entries.source_type', 'instant_sale')
                        ->whereNotNull('sales.maintenance_id'));
            })
            ->selectRaw('entries.currency, SUM(lines.credit - lines.debit) as amount')
            ->groupBy('entries.currency')
            ->get()
            ->keyBy(fn ($row) => $this->normalizeCurrency($row->currency));

        return $operational->keys()->merge($ledger->keys())->unique()
            ->filter(function ($currency) use ($operational, $ledger) {
                $expected = (float) ($operational->get($currency)->amount ?? 0);
                $actual = (float) ($ledger->get($currency)->amount ?? 0);

                return abs($actual - $expected) > 0.01;
            })
            ->map(fn ($currency) => 'customer_deposit_balance:'.$currency)
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function checkExpenses(Carbon $from, Carbon $to): array
    {
        $errors = [];
        Expense::query()->whereBetween('created_at', [$from, $to])->orderBy('id')->chunkById(200, function ($rows) use (&$errors) {
            foreach ($rows as $expense) {
                if ($expense->payment_method === 'carrier_withholding') {
                    continue;
                }
                if ((float) $expense->price <= 0.0001) {
                    continue;
                }
                $accounts = $this->journalAccounts('expense', (int) $expense->id);
                $expenseAccounts = ['general_expense', 'salary_expense', 'inventory_loss', 'delivery_expense'];
                if (! $accounts->intersect($expenseAccounts)->count() || ! $accounts->intersect(['cash', 'clearing', 'salary_payable'])->count()) {
                    $errors[] = (int) $expense->id;
                }
            }
        });

        return $this->check('expenses', $errors === [] ? 'PASS' : 'ERROR', 'Expenses require an expense account and a cash/payable/clearing counterpart.', $errors);
    }

    /** @return array<string, mixed> */
    private function checkAssets(Carbon $from, Carbon $to): array
    {
        $errors = [];
        $warnings = [];
        Asset::query()->whereBetween('created_at', [$from, $to])->orderBy('id')->chunkById(200, function ($rows) use (&$errors, &$warnings) {
            foreach ($rows as $asset) {
                if ((float) $asset->price <= 0.0001) {
                    continue;
                }
                $accounts = $this->journalAccounts('asset', (int) $asset->id);
                if (! $accounts->contains('fixed_assets') || ! $accounts->intersect(['cash', 'clearing'])->count()) {
                    $errors[] = 'asset:'.$asset->id;
                }
                $calculation = $this->assetDepreciation->calculate($asset, now()->format('Y-m'));
                if ($calculation['warning']) {
                    $warnings[] = 'asset_review:'.$asset->id;
                }
            }
        });
        AssetLog::query()->whereBetween('created_at', [$from, $to])->where('type', 'depreciate')->orderBy('id')->chunkById(200, function ($rows) use (&$errors) {
            foreach ($rows as $log) {
                $accounts = $this->journalAccounts('asset_depreciation', (int) $log->id);
                if (! $accounts->contains('depreciation_expense') || ! $accounts->contains('accumulated_depreciation')) {
                    $errors[] = 'depreciation:'.$log->id;
                }
            }
        });

        return $this->check(
            'assets',
            $errors !== [] ? 'ERROR' : ($warnings !== [] ? 'WARNING' : 'PASS'),
            'Asset purchases and depreciation logs require journals; exhausted useful lives with remaining value require review.',
            array_values(array_unique(array_merge($errors, $warnings))),
            ['errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings))],
        );
    }

    /** @return array<string, mixed> */
    private function checkBoxes(Carbon $from, Carbon $to): array
    {
        $errors = [];
        BoxLog::query()->whereBetween('created_at', [$from, $to])
            ->where(fn (Builder $query) => $query->where('type', 'transfer')->orWhereIn('description', ['تم اضافة رصيد للصندوق', 'تم سحب رصيد من الصندوق']))
            ->orderBy('id')->chunkById(200, function ($rows) use (&$errors) {
                foreach ($rows as $log) {
                    $type = $log->type === 'transfer' ? 'box_transfer' : 'box_adjustment';
                    if (! $this->journalAccounts($type, (int) $log->id)->contains('cash')) {
                        $errors[] = (int) $log->id;
                    }
                }
            });

        return $this->check('cashboxes', $errors === [] ? 'PASS' : 'ERROR', 'Transfers and manual cashbox balance changes require accounting effects.', $errors);
    }

    /** @return array<string, mixed> */
    private function checkPartyDimensions(Carbon $from, Carbon $to): array
    {
        $ids = DB::table('accounting_journal_lines as lines')
            ->join('accounting_journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->join('accounting_accounts as accounts', 'accounts.id', '=', 'lines.account_id')
            ->whereDate('entries.entry_date', '>=', $from->toDateString())
            ->whereDate('entries.entry_date', '<=', $to->toDateString())
            ->whereIn('accounts.system_key', ['accounts_receivable', 'accounts_payable'])
            ->whereNull('lines.customer_id')->whereNull('lines.seller_id')->whereNull('lines.delivery_company_id')
            ->where(fn ($query) => $query->whereNull('lines.metadata')->orWhere('lines.metadata', 'not like', '%intentional_unallocated%'))
            ->pluck('lines.id')->map(fn ($id) => (int) $id)->all();

        return $this->check('party_dimensions', $ids === [] ? 'PASS' : 'ERROR', 'AR/AP lines require a customer, supplier, or delivery-company dimension unless explicitly documented.', $ids);
    }

    /** @return array<string, mixed> */
    private function checkInventoryLayersAndAllocations(): array
    {
        $negativeLayers = Schema::hasTable('inventory_cost_layers')
            ? DB::table('inventory_cost_layers')->where('remaining_quantity', '<', -0.0001)->pluck('id')->map(fn ($id) => 'layer:'.$id)->all()
            : [];
        $overAllocated = [];
        if (Schema::hasTable('inventory_cost_allocations') && Schema::hasTable('instant_sales')) {
            $groups = DB::table('inventory_cost_allocations')
                ->where('reference_type', 'instant_sale')
                ->selectRaw('reference_id, SUM(quantity) as allocated_quantity')
                ->groupBy('reference_id')
                ->get();
            foreach ($groups as $group) {
                $sold = DB::table('instant_sales')->where('id', $group->reference_id)->value('quantity');
                if ($sold !== null && (float) $group->allocated_quantity - (float) $sold > 0.0001) {
                    $overAllocated[] = 'instant_sale:'.$group->reference_id;
                }
            }
        }
        $ids = array_merge($negativeLayers, $overAllocated);

        return $this->check('inventory_cost_integrity', $ids === [] ? 'PASS' : 'ERROR', 'FIFO layers cannot be negative and allocation quantity cannot exceed the sold line quantity.', $ids, [
            'negative_layers' => $negativeLayers,
            'over_allocated_sales' => $overAllocated,
        ]);
    }

    /** @return array<string, mixed> */
    private function checkProjectionFailures(Carbon $from, Carbon $to): array
    {
        if (! Schema::hasTable('accounting_projection_failures')) {
            return $this->check('projection_failures', 'ERROR', 'Projection failure table is missing.', []);
        }
        $ids = DB::table('accounting_projection_failures')
            ->whereNull('resolved_at')
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        return $this->check('projection_failures', $ids === [] ? 'PASS' : 'ERROR', 'Open accounting projection failures require repair or documented data remediation.', $ids);
    }

    /** @return Collection<int, string> */
    private function journalAccounts(string $sourceType, int $sourceId): Collection
    {
        return DB::table('accounting_journal_entries as entries')
            ->join('accounting_journal_lines as lines', 'lines.journal_entry_id', '=', 'entries.id')
            ->join('accounting_accounts as accounts', 'accounts.id', '=', 'lines.account_id')
            ->where('entries.source_type', $sourceType)
            ->where('entries.source_id', $sourceId)
            ->where('entries.status', AccountingJournalEntry::STATUS_POSTED)
            ->whereNull('entries.reverses_entry_id')
            ->pluck('accounts.system_key')
            ->filter()->unique()->values();
    }

    private function journalPartyLinked(string $sourceType, int $sourceId, ?int $customerId, ?int $sellerId): bool
    {
        if (! $customerId && ! $sellerId) {
            return false;
        }

        return DB::table('accounting_journal_entries as entries')
            ->join('accounting_journal_lines as lines', 'lines.journal_entry_id', '=', 'entries.id')
            ->join('accounting_accounts as accounts', 'accounts.id', '=', 'lines.account_id')
            ->where('entries.source_type', $sourceType)
            ->where('entries.source_id', $sourceId)
            ->where('entries.status', AccountingJournalEntry::STATUS_POSTED)
            ->whereNull('entries.reverses_entry_id')
            ->whereIn('accounts.system_key', ['accounts_receivable', 'accounts_payable'])
            ->when($customerId, fn ($query) => $query->where('lines.customer_id', $customerId))
            ->when($sellerId, fn ($query) => $query->where('lines.seller_id', $sellerId))
            ->exists();
    }

    private function normalizeCurrency(?string $currency): string
    {
        return match (strtoupper(trim((string) $currency))) {
            'USD', 'دولار' => 'دولار',
            'JOD', 'دينار' => 'دينار',
            default => 'شيكل',
        };
    }

    /** @param array<int, mixed> $ids @param array<string, mixed> $details @return array<string, mixed> */
    private function check(string $name, string $status, string $message, array $ids, array $details = []): array
    {
        return compact('name', 'status', 'message', 'ids', 'details');
    }
}
