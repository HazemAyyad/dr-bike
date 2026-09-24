<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AccountingReconciliationService
{
    private const TOLERANCE = 0.01;

    public function reconcile(): array
    {
        if (! Schema::hasTable('accounting_journal_entries') || ! Schema::hasTable('accounting_journal_lines')) {
            return ['complete' => false, 'mismatch_count' => 0, 'comparisons' => [], 'message' => 'دفتر الأستاذ غير مهيأ.'];
        }

        $comparisons = collect();
        $this->compareCash($comparisons);
        $this->compareInventory($comparisons);
        $this->comparePartyBalances($comparisons);
        $this->compareCustomerDeposits($comparisons);
        $this->compareChecks($comparisons);
        $this->compareAssets($comparisons);
        $this->comparePayroll($comparisons);

        $mismatches = $comparisons->where('matches', false)->values();

        return [
            'complete' => $comparisons->isNotEmpty() && $mismatches->isEmpty(),
            'checked_at' => now()->toIso8601String(),
            'comparison_count' => $comparisons->count(),
            'mismatch_count' => $mismatches->count(),
            'comparisons' => $comparisons->values()->all(),
            'mismatches' => $mismatches->all(),
        ];
    }

    private function compareCash(Collection $comparisons): void
    {
        $operational = DB::table('boxes')
            ->selectRaw("id as dimension_id, COALESCE(NULLIF(currency, ''), 'شيكل') as currency, SUM(total) as amount")
            ->groupBy('id', 'currency')
            ->get();
        $ledger = $this->ledgerBalances('cash', 'lines.box_id');
        $this->merge($comparisons, 'cash_by_box', $operational, $ledger);
    }

    private function compareInventory(Collection $comparisons): void
    {
        if (! Schema::hasTable('inventory_cost_balances')) {
            return;
        }
        $operational = DB::table('inventory_cost_balances')
            ->selectRaw("NULL as dimension_id, COALESCE(NULLIF(currency, ''), 'شيكل') as currency, SUM(inventory_value) as amount")
            ->groupBy('currency')
            ->get();
        $ledger = $this->ledgerBalances('inventory');
        $this->merge($comparisons, 'inventory', $operational, $ledger);
    }

    private function comparePartyBalances(Collection $comparisons): void
    {
        if (! Schema::hasTable('debt_transactions')) {
            return;
        }
        $active = DB::table('debt_transactions')
            ->whereNull('deleted_at')
            ->whereNull('archived_at')
            ->selectRaw("customer_id, seller_id, COALESCE(NULLIF(currency, ''), 'شيكل') as currency")
            ->selectRaw("SUM(CASE WHEN type = 'taken' THEN amount ELSE -amount END) as signed_balance")
            ->groupBy('customer_id', 'seller_id', 'currency')
            ->get();

        $receivable = $active->groupBy('currency')->map(fn ($rows, $currency) => (object) [
            'dimension_id' => null,
            'currency' => $currency,
            'amount' => $rows->sum(fn ($row) => abs(min((float) $row->signed_balance, 0))),
        ])->values();
        if (Schema::hasTable('sales_orders') && Schema::hasColumn('sales_orders', 'carrier_receivable_balance')) {
            $carrierReceivable = (float) DB::table('sales_orders')->sum('carrier_receivable_balance');
            $shekel = $receivable->first(fn ($row) => $this->normalizeCurrency($row->currency) === 'شيكل');
            if ($shekel) {
                $shekel->amount = (float) $shekel->amount + $carrierReceivable;
            } elseif (abs($carrierReceivable) > 0.0001) {
                $receivable->push((object) [
                    'dimension_id' => null,
                    'currency' => 'شيكل',
                    'amount' => $carrierReceivable,
                ]);
            }
        }
        $payable = $active->groupBy('currency')->map(fn ($rows, $currency) => (object) [
            'dimension_id' => null,
            'currency' => $currency,
            'amount' => $rows->sum(fn ($row) => max((float) $row->signed_balance, 0)),
        ])->values();

        $this->merge($comparisons, 'accounts_receivable', $receivable, $this->ledgerBalances('accounts_receivable'));
        $this->merge($comparisons, 'accounts_payable', $payable, $this->ledgerBalances('accounts_payable', null, true));
    }

    private function compareChecks(Collection $comparisons): void
    {
        if (Schema::hasTable('incoming_checks')) {
            $incoming = DB::table('incoming_checks')
                ->where('status', 'not_cashed')
                ->selectRaw("NULL as dimension_id, COALESCE(NULLIF(currency, ''), 'شيكل') as currency, SUM(total) as amount")
                ->groupBy('currency')
                ->get();
            $this->merge($comparisons, 'checks_receivable', $incoming, $this->ledgerBalances('checks_receivable'));
        }
        if (Schema::hasTable('outgoing_checks')) {
            $outgoing = DB::table('outgoing_checks')
                ->whereIn('status', ['not_cashed', 'cashed_to_person'])
                ->selectRaw("NULL as dimension_id, COALESCE(NULLIF(currency, ''), 'شيكل') as currency, SUM(total) as amount")
                ->groupBy('currency')
                ->get();
            $this->merge($comparisons, 'checks_payable', $outgoing, $this->ledgerBalances('checks_payable', null, true));
        }
    }

    private function compareCustomerDeposits(Collection $comparisons): void
    {
        $sources = collect();

        if (Schema::hasTable('sales_order_settlements')
            && Schema::hasTable('sales_orders')
            && Schema::hasTable('boxes')) {
            $sources = $sources->concat(DB::table('sales_order_settlements as settlements')
                ->join('sales_orders as orders', 'orders.id', '=', 'settlements.sales_order_id')
                ->leftJoin('boxes', 'boxes.id', '=', 'settlements.box_id')
                ->where('settlements.source', 'order_payment')
                ->where('settlements.cash_amount', '>', 0)
                ->whereNull('orders.financial_posted_at')
                ->where('orders.is_debt_collection', false)
                ->whereNotIn('orders.status', ['canceled', 'returned', 'archived'])
                ->selectRaw("NULL as dimension_id, COALESCE(NULLIF(boxes.currency, ''), 'شيكل') as currency, SUM(settlements.cash_amount) as amount")
                ->groupBy('boxes.currency')
                ->get());
        }

        if (Schema::hasTable('maintenance_payments')
            && Schema::hasTable('maintenance')
            && Schema::hasColumn('maintenance_payments', 'payment_stage')) {
            $sources = $sources->concat(DB::table('maintenance_payments as payments')
                ->join('maintenance as maintenance', 'maintenance.id', '=', 'payments.maintenance_id')
                ->where('payments.payment_stage', 'pre_delivery')
                ->whereNull('maintenance.instant_sale_id')
                ->where('maintenance.status', '!=', 'delivered')
                ->when(
                    Schema::hasColumn('maintenance', 'deleted_at'),
                    fn ($query) => $query->whereNull('maintenance.deleted_at'),
                )
                ->selectRaw("NULL as dimension_id, COALESCE(NULLIF(payments.currency, ''), 'شيكل') as currency, SUM(payments.amount) as amount")
                ->groupBy('payments.currency')
                ->get());
        }

        $operational = $sources
            ->groupBy(fn ($row) => $this->normalizeCurrency($row->currency ?? null))
            ->map(fn ($rows, $currency) => (object) [
                'dimension_id' => null,
                'currency' => $currency,
                'amount' => $rows->sum(fn ($row) => (float) $row->amount),
            ])
            ->values();

        $this->merge(
            $comparisons,
            'customer_deposits',
            $operational,
            $this->ledgerBalances('customer_deposits', null, true),
        );
    }

    private function compareAssets(Collection $comparisons): void
    {
        if (! Schema::hasTable('assets')) {
            return;
        }
        $currencySql = Schema::hasColumn('assets', 'currency')
            ? "COALESCE(NULLIF(currency, ''), 'شيكل')"
            : "'شيكل'";
        $cost = DB::table('assets')
            ->selectRaw("NULL as dimension_id, {$currencySql} as currency, SUM(price) as amount")
            ->groupByRaw($currencySql)
            ->get();
        $depreciation = DB::table('assets')
            ->selectRaw("NULL as dimension_id, {$currencySql} as currency, SUM(CASE WHEN price > depreciation_price THEN price - depreciation_price ELSE 0 END) as amount")
            ->groupByRaw($currencySql)
            ->get();
        $this->merge($comparisons, 'fixed_assets', $cost, $this->ledgerBalances('fixed_assets'));
        $this->merge($comparisons, 'accumulated_depreciation', $depreciation, $this->ledgerBalances('accumulated_depreciation', null, true));
    }

    private function comparePayroll(Collection $comparisons): void
    {
        if (Schema::hasTable('employee_orders')) {
            $applicationTable = Schema::hasTable('employee_advance_applications');
            $advanceRows = DB::table('employee_orders as orders')
                ->when($applicationTable, fn ($query) => $query->leftJoin('employee_advance_applications as applications', 'applications.employee_order_id', '=', 'orders.id'))
                ->where('orders.type', 'loan')
                ->whereIn('orders.status', ['approved', 'paid'])
                ->whereNull('orders.cancelled_at')
                ->groupBy('orders.id', 'orders.loan_value')
                ->selectRaw('GREATEST(orders.loan_value - '.($applicationTable ? 'COALESCE(SUM(applications.amount), 0)' : '0').', 0) as outstanding')
                ->get();
            $outstanding = collect([(object) [
                'dimension_id' => null,
                'currency' => 'شيكل',
                'amount' => $advanceRows->sum('outstanding'),
            ]]);
            $this->merge($comparisons, 'employee_advances', $outstanding, $this->ledgerBalances('employee_advances'));
        }
        if (Schema::hasTable('employee_salary_periods')) {
            $payable = DB::table('employee_salary_periods')
                ->selectRaw("NULL as dimension_id, 'شيكل' as currency, SUM(remaining) as amount")
                ->get();
            $this->merge($comparisons, 'salary_payable', $payable, $this->ledgerBalances('salary_payable', null, true));
        }
    }

    private function ledgerBalances(string $accountKey, ?string $dimension = null, bool $creditNormal = false): Collection
    {
        $dimensionSql = $dimension ?: 'NULL';
        $balanceSql = $creditNormal ? 'SUM(lines.credit - lines.debit)' : 'SUM(lines.debit - lines.credit)';

        return DB::table('accounting_journal_lines as lines')
            ->join('accounting_journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->join('accounting_accounts as accounts', 'accounts.id', '=', 'lines.account_id')
            ->where('accounts.system_key', $accountKey)
            ->selectRaw("{$dimensionSql} as dimension_id, entries.currency, {$balanceSql} as amount")
            ->groupBy('entries.currency')
            ->when($dimension, fn ($query) => $query->groupByRaw($dimension))
            ->get();
    }

    private function merge(Collection $comparisons, string $scope, Collection $operational, Collection $ledger): void
    {
        $normalize = fn ($row) => ($row->dimension_id ?? 'all').'|'.$this->normalizeCurrency($row->currency ?? null);
        $operational = $operational->keyBy($normalize);
        $ledger = $ledger->keyBy($normalize);

        foreach ($operational->keys()->merge($ledger->keys())->unique() as $key) {
            $source = $operational->get($key) ?: $ledger->get($key);
            $actual = round((float) ($operational->get($key)->amount ?? 0), 4);
            $book = round((float) ($ledger->get($key)->amount ?? 0), 4);
            $difference = round($book - $actual, 4);
            $comparisons->push([
                'scope' => $scope,
                'dimension_id' => ($source->dimension_id ?? null) !== null ? (int) $source->dimension_id : null,
                'currency' => $this->normalizeCurrency($source->currency ?? null),
                'operational_balance' => $actual,
                'ledger_balance' => $book,
                'difference' => $difference,
                'matches' => abs($difference) <= self::TOLERANCE,
            ]);
        }
    }

    private function normalizeCurrency(?string $currency): string
    {
        return match (strtoupper(trim((string) $currency))) {
            'USD', 'دولار' => 'دولار',
            'JOD', 'دينار' => 'دينار',
            default => 'شيكل',
        };
    }
}
