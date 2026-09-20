<?php

namespace App\Services;

use App\Models\AccountingCutover;
use App\Models\Asset;
use App\Models\Box;
use App\Models\DebtTransaction;
use App\Models\EmployeeOrder;
use App\Models\EmployeeSalaryPeriod;
use App\Models\IncomingCheck;
use App\Models\InventoryCostBalance;
use App\Models\OutgoingCheck;
use App\Models\SalesOrder;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class AccountingCutoverService
{
    public function __construct(private AccountingService $accounting) {}

    public function preview(Carbon|string|null $date = null): array
    {
        $cutoverDate = Carbon::parse($date ?: now())->toDateString();
        $lines = collect();
        $issues = collect();

        $this->cashLines($lines, $issues);
        $this->inventoryLines($lines, $issues);
        $this->assetLines($lines);
        $this->partyBalanceLines($lines, $issues);
        $this->carrierReceivableLines($lines, $issues);
        $this->customerDepositLines($lines);
        $this->checkLines($lines);
        $this->payrollLines($lines);

        $currencies = $lines->groupBy('currency')->map(function (Collection $currencyLines, string $currency) {
            $debit = round((float) $currencyLines->sum('debit'), 4);
            $credit = round((float) $currencyLines->sum('credit'), 4);
            $difference = round($debit - $credit, 4);
            $balancedLines = $currencyLines->map(fn (array $line) => collect($line)->except('currency')->all())->values();
            if ($difference > 0.0001) {
                $balancedLines->push(['account_key' => 'opening_balance', 'debit' => 0, 'credit' => $difference, 'description' => 'مقابل الأرصدة الافتتاحية']);
            } elseif ($difference < -0.0001) {
                $balancedLines->push(['account_key' => 'opening_balance', 'debit' => abs($difference), 'credit' => 0, 'description' => 'مقابل الأرصدة الافتتاحية']);
            }

            return [
                'currency' => $currency,
                'debit_before_equity' => $debit,
                'credit_before_equity' => $credit,
                'opening_equity' => abs($difference),
                'lines_count' => $balancedLines->count(),
                'lines' => $balancedLines->all(),
            ];
        })->values();

        return [
            'cutover_date' => $cutoverDate,
            'generated_at' => now()->toIso8601String(),
            'can_apply' => $issues->where('severity', 'blocking')->isEmpty() && $currencies->isNotEmpty(),
            'issues' => $issues->values()->all(),
            'currencies' => $currencies->all(),
            'summary' => [
                'boxes' => Box::query()->count(),
                'inventory_cost_balances' => Schema::hasTable('inventory_cost_balances') ? InventoryCostBalance::query()->count() : 0,
                'assets' => Asset::query()->count(),
                'active_debt_transactions' => DebtTransaction::query()->active()->count(),
                'open_carrier_receivables' => Schema::hasTable('sales_orders') ? SalesOrder::query()->where('carrier_receivable_balance', '>', 0)->count() : 0,
                'open_customer_deposits' => Schema::hasTable('sales_order_settlements')
                    ? DB::table('sales_order_settlements')->where('source', 'order_payment')->where('cash_amount', '>', 0)->count()
                    : 0,
                'open_incoming_checks' => IncomingCheck::query()->where('status', 'not_cashed')->count(),
                'open_outgoing_checks' => OutgoingCheck::query()->whereIn('status', ['not_cashed', 'cashed_to_person'])->count(),
                'employee_advances' => Schema::hasTable('employee_orders') ? EmployeeOrder::query()->where('type', 'loan')->whereIn('status', ['approved', 'paid'])->count() : 0,
                'salary_periods_outstanding' => Schema::hasTable('employee_salary_periods') ? EmployeeSalaryPeriod::query()->where('remaining', '>', 0)->count() : 0,
            ],
        ];
    }

    public function apply(Carbon|string|null $date = null, ?int $userId = null, ?string $notes = null, bool $force = false): AccountingCutover
    {
        if (AccountingCutover::query()->where('status', 'applied')->exists()) {
            throw ValidationException::withMessages(['cutover' => ['تم تطبيق تهيئة محاسبية افتتاحية مسبقًا.']]);
        }
        if (DB::table('accounting_journal_entries')->exists()) {
            throw ValidationException::withMessages(['cutover' => ['يجب أن يكون دفتر الأستاذ فارغًا قبل تطبيق التهيئة الافتتاحية.']]);
        }

        $preview = $this->preview($date);
        if (! $preview['can_apply'] && ! $force) {
            throw ValidationException::withMessages(['cutover' => ['توجد مشاكل مانعة. راجع نتيجة المعاينة أو استخدم --force بعد التحقق اليدوي.']]);
        }

        return DB::transaction(function () use ($preview, $userId, $notes) {
            $cutover = AccountingCutover::query()->create([
                'cutover_date' => $preview['cutover_date'],
                'status' => 'applied',
                'snapshot' => $preview,
                'notes' => $notes,
                'applied_by' => $userId,
                'applied_at' => now(),
            ]);
            $entryIds = [];
            foreach ($preview['currencies'] as $currency) {
                if (count($currency['lines']) < 2) {
                    continue;
                }
                $entry = $this->accounting->post(
                    'accounting_cutover:'.$cutover->id.':'.$currency['currency'],
                    'accounting_cutover',
                    (int) $cutover->id,
                    $preview['cutover_date'],
                    $currency['currency'],
                    'الأرصدة الافتتاحية عند بدء دفتر الأستاذ',
                    $currency['lines'],
                    ['cutover_date' => $preview['cutover_date']],
                    $userId,
                );
                $entryIds[] = $entry->id;
            }
            $cutover->update(['journal_entry_ids' => $entryIds]);

            return $cutover->fresh();
        });
    }

    private function cashLines(Collection $lines, Collection $issues): void
    {
        foreach (Box::query()->get(['id', 'name', 'total', 'currency']) as $box) {
            $amount = round((float) $box->total, 4);
            if (abs($amount) <= 0.0001) {
                continue;
            }
            if (! $box->currency) {
                $issues->push(['severity' => 'warning', 'code' => 'box_currency_missing', 'message' => 'صندوق #'.$box->id.' بلا عملة وسيعامل كشيكل.']);
            }
            $lines->push($this->signedLine('cash', $amount, $box->currency, [
                'box_id' => $box->id,
                'description' => 'رصيد افتتاحي للصندوق '.$box->name,
            ]));
        }
    }

    private function inventoryLines(Collection $lines, Collection $issues): void
    {
        if (! Schema::hasTable('inventory_cost_balances')) {
            $issues->push(['severity' => 'blocking', 'code' => 'inventory_cost_balances_missing', 'message' => 'جدول أرصدة تكلفة المخزون غير موجود.']);

            return;
        }
        $reviewCount = InventoryCostBalance::query()->where('needs_review', true)->count();
        if ($reviewCount > 0) {
            $issues->push(['severity' => 'blocking', 'code' => 'inventory_cost_review', 'message' => $reviewCount.' أرصدة مخزون تحتاج مراجعة تكلفة.']);
        }
        $rows = InventoryCostBalance::query()
            ->select(['product_id', 'currency'])
            ->selectRaw('SUM(inventory_value) as inventory_value')
            ->groupBy('product_id', 'currency')
            ->get();
        foreach ($rows as $row) {
            $amount = round((float) $row->inventory_value, 4);
            if (abs($amount) <= 0.0001) {
                continue;
            }
            $lines->push($this->signedLine('inventory', $amount, $row->currency, [
                'product_id' => $row->product_id,
                'description' => 'قيمة مخزون افتتاحية للمنتج #'.$row->product_id,
            ]));
        }
    }

    private function assetLines(Collection $lines): void
    {
        foreach (Asset::query()->get(['id', 'name', 'price', 'depreciation_price']) as $asset) {
            $cost = round(max(0, (float) $asset->price), 4);
            $accumulated = round(max(0, $cost - (float) $asset->depreciation_price), 4);
            if ($cost > 0.0001) {
                $lines->push($this->signedLine('fixed_assets', $cost, 'شيكل', ['description' => 'تكلفة أصل افتتاحية: '.$asset->name, 'metadata' => ['asset_id' => $asset->id]]));
            }
            if ($accumulated > 0.0001) {
                $lines->push($this->signedLine('accumulated_depreciation', -$accumulated, 'شيكل', ['description' => 'مجمع إهلاك افتتاحي: '.$asset->name, 'metadata' => ['asset_id' => $asset->id]]));
            }
        }
    }

    private function partyBalanceLines(Collection $lines, Collection $issues): void
    {
        $invalid = DebtTransaction::query()->active()->whereNotNull('customer_id')->whereNotNull('seller_id')->count();
        if ($invalid > 0) {
            $issues->push(['severity' => 'blocking', 'code' => 'ambiguous_party', 'message' => $invalid.' حركات دين مرتبطة بزبون ومورد معًا.']);
        }
        $rows = DebtTransaction::query()->active()
            ->select(['customer_id', 'seller_id', 'currency'])
            ->selectRaw("SUM(CASE WHEN type = 'taken' THEN amount ELSE -amount END) as signed_balance")
            ->groupBy('customer_id', 'seller_id', 'currency')
            ->get();
        foreach ($rows as $row) {
            $balance = round((float) $row->signed_balance, 4);
            if (abs($balance) <= 0.0001) {
                continue;
            }
            $extra = ['customer_id' => $row->customer_id, 'seller_id' => $row->seller_id];
            if ($balance < 0) {
                $lines->push($this->signedLine('accounts_receivable', abs($balance), $row->currency, array_merge($extra, ['description' => 'رصيد ذمم مدينة افتتاحي'])));
            } else {
                $lines->push($this->signedLine('accounts_payable', -$balance, $row->currency, array_merge($extra, ['description' => 'رصيد ذمم دائنة افتتاحي'])));
            }
        }
    }

    private function checkLines(Collection $lines): void
    {
        foreach (IncomingCheck::query()->where('status', 'not_cashed')->get() as $check) {
            $lines->push($this->signedLine('checks_receivable', (float) $check->total, $check->currency, [
                'customer_id' => $check->from_customer, 'seller_id' => $check->from_seller,
                'due_date' => $check->due_date, 'description' => 'شيك وارد افتتاحي '.$check->check_id,
                'metadata' => ['incoming_check_id' => $check->id],
            ]));
        }
        foreach (OutgoingCheck::query()->whereIn('status', ['not_cashed', 'cashed_to_person'])->get() as $check) {
            $lines->push($this->signedLine('checks_payable', -(float) $check->total, $check->currency, [
                'customer_id' => $check->customer_id, 'seller_id' => $check->seller_id,
                'due_date' => $check->due_date, 'description' => 'شيك صادر افتتاحي '.$check->check_id,
                'metadata' => ['outgoing_check_id' => $check->id],
            ]));
        }
    }

    private function carrierReceivableLines(Collection $lines, Collection $issues): void
    {
        if (! Schema::hasTable('sales_orders') || ! Schema::hasColumn('sales_orders', 'carrier_receivable_balance')) {
            return;
        }

        $missingCompany = SalesOrder::query()
            ->where('carrier_receivable_balance', '>', 0)
            ->whereNull('delivery_company_id')
            ->count();
        if ($missingCompany > 0) {
            $issues->push([
                'severity' => 'blocking',
                'code' => 'carrier_receivable_company_missing',
                'message' => $missingCompany.' أرصدة شركات توصيل بلا شركة مرتبطة.',
            ]);
        }

        $rows = SalesOrder::query()
            ->where('carrier_receivable_balance', '>', 0)
            ->whereNotNull('delivery_company_id')
            ->select('delivery_company_id')
            ->selectRaw('SUM(carrier_receivable_balance) as balance')
            ->groupBy('delivery_company_id')
            ->get();
        foreach ($rows as $row) {
            $lines->push($this->signedLine('accounts_receivable', (float) $row->balance, 'شيكل', [
                'delivery_company_id' => $row->delivery_company_id,
                'description' => 'رصيد افتتاحي على شركة التوصيل #'.$row->delivery_company_id,
            ]));
        }
    }

    private function customerDepositLines(Collection $lines): void
    {
        if (! Schema::hasTable('sales_order_settlements')
            || ! Schema::hasTable('sales_orders')
            || ! Schema::hasTable('boxes')) {
            return;
        }

        $rows = DB::table('sales_order_settlements as settlements')
            ->join('sales_orders as orders', 'orders.id', '=', 'settlements.sales_order_id')
            ->leftJoin('boxes', 'boxes.id', '=', 'settlements.box_id')
            ->where('settlements.source', 'order_payment')
            ->where('settlements.cash_amount', '>', 0)
            ->whereNull('orders.financial_posted_at')
            ->where('orders.is_debt_collection', false)
            ->whereNotIn('orders.status', ['canceled', 'returned', 'archived'])
            ->select(['orders.customer_id', 'boxes.currency'])
            ->selectRaw('SUM(settlements.cash_amount) as balance')
            ->groupBy('orders.customer_id', 'boxes.currency')
            ->get();

        foreach ($rows as $row) {
            $lines->push($this->signedLine('customer_deposits', -(float) $row->balance, $row->currency, [
                'customer_id' => $row->customer_id,
                'description' => 'دفعات زبائن مقدمة افتتاحية',
            ]));
        }
    }

    private function payrollLines(Collection $lines): void
    {
        if (Schema::hasTable('employee_orders')) {
            $applicationTable = Schema::hasTable('employee_advance_applications');
            $advances = DB::table('employee_orders as orders')
                ->when($applicationTable, fn ($query) => $query->leftJoin('employee_advance_applications as applications', 'applications.employee_order_id', '=', 'orders.id'))
                ->where('orders.type', 'loan')
                ->whereIn('orders.status', ['approved', 'paid'])
                ->whereNull('orders.cancelled_at')
                ->groupBy('orders.id', 'orders.employee_id', 'orders.loan_value')
                ->selectRaw('orders.id, orders.employee_id, GREATEST(orders.loan_value - '.($applicationTable ? 'COALESCE(SUM(applications.amount), 0)' : '0').', 0) as outstanding')
                ->get();
            foreach ($advances as $advance) {
                if ((float) $advance->outstanding <= 0.0001) {
                    continue;
                }
                $lines->push($this->signedLine('employee_advances', (float) $advance->outstanding, 'شيكل', [
                    'description' => 'رصيد سلفة افتتاحي للموظف #'.$advance->employee_id,
                    'metadata' => ['employee_order_id' => $advance->id, 'employee_id' => $advance->employee_id],
                ]));
            }
        }

        if (Schema::hasTable('employee_salary_periods')) {
            foreach (EmployeeSalaryPeriod::query()->where('remaining', '>', 0)->get(['id', 'employee_id', 'remaining']) as $period) {
                $lines->push($this->signedLine('salary_payable', -(float) $period->remaining, 'شيكل', [
                    'description' => 'راتب مستحق افتتاحي للموظف #'.$period->employee_id,
                    'metadata' => ['salary_period_id' => $period->id, 'employee_id' => $period->employee_id],
                ]));
            }
        }
    }

    private function signedLine(string $accountKey, float $signedDebit, ?string $currency, array $extra = []): array
    {
        return array_merge([
            'currency' => $this->accounting->normalizeCurrency($currency),
            'account_key' => $accountKey,
            'debit' => $signedDebit > 0 ? abs($signedDebit) : 0,
            'credit' => $signedDebit < 0 ? abs($signedDebit) : 0,
        ], array_filter($extra, fn ($value) => $value !== null));
    }
}
