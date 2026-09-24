<?php

namespace App\Services;

use App\Models\AccountingJournalEntry;
use App\Models\Asset;
use App\Models\AssetLog;
use App\Models\BoxLog;
use App\Models\DebtTransaction;
use App\Models\EmployeeAdvanceApplication;
use App\Models\EmployeeOrder;
use App\Models\Expense;
use App\Models\IncomingCheck;
use App\Models\InstantSale;
use App\Models\InventoryAdjustment;
use App\Models\MaintenancePayment;
use App\Models\OutgoingCheck;
use App\Models\ProfitSale;
use App\Models\ProjectExpense;
use App\Models\PurchasePayment;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptItem;
use App\Models\ReturnModel;
use App\Models\SalaryPaymentItem;
use App\Models\SalesOrder;
use App\Models\SalesOrderSettlement;
use App\Models\SalesReturn;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class AccountingProjectionService
{
    public function __construct(
        private AccountingService $accounting,
        private InventoryCostIntegrityService $inventoryIntegrity,
    ) {}

    public function sync(Model $model): ?AccountingJournalEntry
    {
        if (! Schema::hasTable('accounting_journal_entries')) {
            return null;
        }

        try {
            return $this->syncOrFail($model);
        } catch (Throwable $e) {
            $this->recordFailure($model, $e);
            report($e);

            return null;
        }
    }

    /**
     * Project one source and let the caller handle any failure. Repair tooling
     * uses this method so a failed retry can never be mistaken for a success.
     */
    public function syncOrFail(Model $model): ?AccountingJournalEntry
    {
        if (! Schema::hasTable('accounting_journal_entries')) {
            return null;
        }

        if ($this->predatesAppliedCutover($model)
            && ! ($model instanceof InstantSale)
            && ! ($model instanceof ProfitSale)
            && ! ($model instanceof IncomingCheck)
            && ! ($model instanceof OutgoingCheck)
            && ! ($model instanceof EmployeeOrder)) {
            $this->resolveFailure($model);

            return null;
        }

        $entry = match (true) {
            $model instanceof InstantSale => $this->syncInstantSale($model),
            $model instanceof MaintenancePayment => $this->syncMaintenancePayment($model),
            $model instanceof InventoryAdjustment => $this->syncInventoryAdjustment($model),
            $model instanceof ProfitSale => $this->syncProfitSale($model),
            $model instanceof Expense => $this->syncExpense($model),
            $model instanceof EmployeeOrder => $this->syncEmployeeAdvance($model),
            $model instanceof EmployeeAdvanceApplication => $this->syncEmployeeAdvanceApplication($model),
            $model instanceof SalaryPaymentItem => $this->syncSalaryPayment($model),
            $model instanceof SalesReturn => $this->syncSalesReturn($model),
            $model instanceof PurchaseReceipt => $this->syncPurchaseReceipt($model),
            $model instanceof PurchaseReceiptItem => $this->syncPurchaseReceipt($model->receipt),
            $model instanceof PurchasePayment => $this->syncPurchasePayment($model),
            $model instanceof ReturnModel => $this->syncPurchaseReturn($model),
            $model instanceof Asset => $this->syncAsset($model),
            $model instanceof AssetLog => $this->syncAssetLog($model),
            $model instanceof ProjectExpense => $this->syncProjectExpense($model),
            $model instanceof IncomingCheck => $this->syncIncomingCheck($model),
            $model instanceof OutgoingCheck => $this->syncOutgoingCheck($model),
            $model instanceof BoxLog => $this->syncBoxLog($model),
            $model instanceof SalesOrder => $this->syncSalesOrder($model),
            $model instanceof DebtTransaction => $this->syncDebtCashMovement($model),
            $model instanceof SalesOrderSettlement => $this->syncSalesOrderSettlement($model),
            default => null,
        };

        $this->resolveFailure($model);

        return $entry;
    }

    public function recordFailureFor(Model $model, Throwable $exception): void
    {
        $this->recordFailure($model, $exception);
    }

    public function reverse(Model $model): ?AccountingJournalEntry
    {
        if (! Schema::hasTable('accounting_journal_entries')) {
            return null;
        }

        $source = $this->sourceIdentity($model);
        if (! $source) {
            return null;
        }

        try {
            return $this->accounting->reverse(
                $source['type'],
                $source['id'],
                now(),
                'عكس تلقائي بعد حذف أو إلغاء المصدر',
                auth()->id(),
            );
        } catch (Throwable $e) {
            $this->recordFailure($model, $e);
            report($e);

            return null;
        }
    }

    private function syncInstantSale(InstantSale $sale): ?AccountingJournalEntry
    {
        if ($sale->parent_id) {
            $sale = InstantSale::query()->find($sale->parent_id);
            if (! $sale) {
                return null;
            }
        }

        if ($this->createdBeforeCutover($sale)) {
            return $this->syncLegacyInstantSaleCancellation($sale);
        }

        if ($sale->isCancelled()) {
            return $this->accounting->reverse('instant_sale', (int) $sale->id, $sale->cancelled_at ?: now(), 'عكس فاتورة مبيعات ملغاة', auth()->id());
        }

        $sale->loadMissing(['subProducts', 'paymentBox', 'salesOrder', 'maintenance.payments']);
        if ($sale->sales_order_id && ! $sale->salesOrder?->financial_posted_at) {
            return null;
        }
        $costInspection = $this->inventoryIntegrity->assertInstantSaleReady($sale);

        $total = round(max(0, (float) $sale->total_cost), 4);
        if ($total <= 0) {
            if ($costInspection['requires_cost']) {
                throw new RuntimeException('Product sale '.$sale->id.' has zero revenue but a FIFO inventory cost; accounting treatment requires review.');
            }

            return $this->accounting->reverse('instant_sale', (int) $sale->id, now(), 'إزالة فاتورة مبيعات صفرية', auth()->id());
        }

        $paid = min($total, round(max(0, (float) ($sale->payment_box_value ?? 0)), 4));
        $depositApplied = 0.0;
        if ($sale->sales_order_id) {
            $depositApplied = min($paid, round((float) $sale->salesOrder?->settlements()
                ->where('source', 'order_payment')
                ->where('cash_amount', '>', 0)
                ->sum('cash_amount'), 4));
        } elseif ($sale->maintenance_id) {
            $typedPayments = $sale->maintenance?->payments
                ?->whereNotNull('payment_stage')
                ->values() ?? collect();

            if ($typedPayments->isNotEmpty()) {
                $paymentCurrencies = $typedPayments
                    ->pluck('currency')
                    ->map(fn ($currency) => $this->accounting->normalizeCurrency($currency))
                    ->filter()
                    ->unique();
                $saleCurrency = $this->accounting->normalizeCurrency($sale->paymentBox?->currency ?: 'شيكل');
                if ($paymentCurrencies->contains(fn ($currency) => $currency !== $saleCurrency)) {
                    throw new RuntimeException('Maintenance sale '.$sale->id.' contains payments in a currency different from the invoice currency.');
                }

                $prepaid = round((float) $typedPayments
                    ->where('payment_stage', MaintenancePayment::STAGE_PRE_DELIVERY)
                    ->sum('amount'), 4);
                $deliveryPaid = round((float) $typedPayments
                    ->where('payment_stage', MaintenancePayment::STAGE_DELIVERY)
                    ->sum('amount'), 4);
                $recordedPaid = round($prepaid + $deliveryPaid, 4);

                if ($prepaid < -0.0001 || $recordedPaid < -0.0001 || $recordedPaid - $total > 0.0001) {
                    throw new RuntimeException('Maintenance sale '.$sale->id.' has invalid typed payment totals.');
                }
                if (abs($recordedPaid - (float) ($sale->payment_box_value ?? 0)) > 0.01) {
                    throw new RuntimeException('Maintenance sale '.$sale->id.' payment records do not match the invoice paid amount.');
                }

                $paid = min($total, max(0, $recordedPaid));
                $depositApplied = min($paid, max(0, $prepaid));
            }
        }
        $cashAtSale = round($paid - $depositApplied, 4);
        $receivable = round($total - $paid, 4);
        if (abs(($depositApplied + $cashAtSale + $receivable) - $total) > 0.0001) {
            throw new RuntimeException('Sale '.$sale->id.' settlement does not balance to the invoice total.');
        }
        $cost = round((float) $costInspection['total_cost'], 4);
        $currency = $sale->paymentBox?->currency ?: 'شيكل';
        $journalLines = [];
        if ($cashAtSale > 0) {
            $journalLines[] = $this->line('cash', $cashAtSale, 0, $sale, ['box_id' => $sale->payment_box_id]);
        }
        if ($depositApplied > 0) {
            $journalLines[] = $this->line('customer_deposits', $depositApplied, 0, $sale, [
                'customer_id' => $sale->customer_id ?: $sale->buyer_id,
            ]);
        }
        if ($receivable > 0) {
            $entity = [
                'customer_id' => $sale->customer_id ?: $sale->buyer_id,
                'seller_id' => $sale->seller_id,
            ];
            $existing = AccountingJournalEntry::query()
                ->where('source_type', 'instant_sale')
                ->where('source_id', $sale->id)
                ->where('status', AccountingJournalEntry::STATUS_POSTED)
                ->whereNull('reverses_entry_id')
                ->latest('id')
                ->first();
            $lockedAllocation = is_array($existing?->metadata)
                ? ($existing->metadata['receivable_allocation'] ?? null)
                : null;
            $carrierReceivable = min($receivable, round(max(0, (float) (
                is_array($lockedAllocation)
                    ? ($lockedAllocation['carrier'] ?? 0)
                    : ($sale->salesOrder?->carrier_receivable_balance ?? 0)
            )), 4));
            $customerReceivable = round($receivable - $carrierReceivable, 4);
            if ($customerReceivable > 0.0001) {
                $debtSource = $sale->sales_order_id ? 'sales_order' : ($sale->maintenance_id ? 'maintenance' : 'instant_sale');
                $debtSourceId = (int) ($sale->sales_order_id ?: $sale->maintenance_id ?: $sale->id);
                $journalLines = array_merge($journalLines, $this->partyEffectLines(
                    $sale,
                    $entity,
                    $customerReceivable,
                    'given',
                    $this->partyBalanceBeforeSource(
                        $entity['customer_id'],
                        $entity['seller_id'],
                        $currency,
                        $debtSource,
                        $debtSourceId,
                    ),
                ));
            }
            if ($carrierReceivable > 0.0001) {
                $journalLines[] = $this->line('accounts_receivable', $carrierReceivable, 0, $sale, [
                    'customer_id' => null,
                    'seller_id' => null,
                    'delivery_company_id' => $sale->salesOrder?->delivery_company_id,
                ]);
            }
        }
        $journalLines[] = $this->line($sale->maintenance_id ? 'maintenance_revenue' : 'sales_revenue', 0, $total, $sale);
        if ($cost > 0) {
            $journalLines[] = $this->line('cost_of_goods_sold', $cost, 0, $sale);
            $journalLines[] = $this->line('inventory', 0, $cost, $sale);
        }

        return $this->accounting->post(
            'instant_sale:'.$sale->id,
            'instant_sale',
            (int) $sale->id,
            $sale->created_at ?: now(),
            $currency,
            ($sale->maintenance_id ? 'فاتورة صيانة ' : 'فاتورة مبيعات ').($sale->serial_number ?: '#'.$sale->id),
            $journalLines,
            [
                'cost_coverage_complete' => true,
                'maintenance_id' => $sale->maintenance_id,
                'customer_deposit_applied' => $depositApplied,
                'receivable_allocation' => [
                    'customer' => $receivable > 0 ? $customerReceivable : 0,
                    'carrier' => $receivable > 0 ? $carrierReceivable : 0,
                ],
            ],
            $sale->created_by ?: auth()->id(),
        );
    }

    private function syncMaintenancePayment(MaintenancePayment $payment): ?AccountingJournalEntry
    {
        if ($payment->payment_stage !== MaintenancePayment::STAGE_PRE_DELIVERY) {
            return null;
        }

        $payment->loadMissing(['maintenance', 'box']);
        $amount = round((float) $payment->amount, 4);
        if (abs($amount) <= 0.0001) {
            return null;
        }
        if (! $payment->box_id) {
            throw new RuntimeException('Maintenance prepayment '.$payment->id.' is missing its cash box.');
        }

        $entity = [
            'customer_id' => $payment->maintenance?->customer_id,
            'seller_id' => $payment->maintenance?->seller_id,
            'box_id' => $payment->box_id,
        ];
        $absoluteAmount = abs($amount);
        $lines = $amount > 0
            ? [
                $this->line('cash', $absoluteAmount, 0, $payment, $entity),
                $this->line('customer_deposits', 0, $absoluteAmount, $payment, $entity),
            ]
            : [
                $this->line('customer_deposits', $absoluteAmount, 0, $payment, $entity),
                $this->line('cash', 0, $absoluteAmount, $payment, $entity),
            ];

        return $this->accounting->post(
            'maintenance_payment:'.$payment->id.':deposit',
            'maintenance_payment',
            (int) $payment->id,
            $payment->created_at ?: now(),
            $payment->currency ?: $payment->box?->currency ?: 'شيكل',
            $amount > 0
                ? 'عربون صيانة #'.$payment->maintenance_id
                : 'عكس عربون صيانة #'.$payment->maintenance_id,
            $lines,
            [
                'maintenance_id' => $payment->maintenance_id,
                'payment_stage' => $payment->payment_stage,
                'instant_sale_id' => $payment->instant_sale_id,
                'payment_method' => $payment->method,
            ],
            $payment->created_by ?: auth()->id(),
        );
    }

    private function syncProfitSale(ProfitSale $sale): ?AccountingJournalEntry
    {
        if ($this->createdBeforeCutover($sale)) {
            if (! $sale->isCancelled() || ! $this->happenedAfterCutover($sale->cancelled_at ?: $sale->updated_at)) {
                return null;
            }
            $sale->loadMissing('paymentBox');
            $total = round(max(0, (float) $sale->total_cost), 4);
            $cash = min($total, round(max(0, (float) $sale->payment_box_value), 4));
            $lines = [$this->line('sales_returns', $total, 0, $sale)];
            if ($cash > 0) {
                $lines[] = $this->line('cash', 0, $cash, $sale, ['box_id' => $sale->payment_box_id]);
            }
            if ($total - $cash > 0) {
                $lines[] = $this->line('accounts_receivable', 0, $total - $cash, $sale);
            }

            return $this->accounting->post(
                'profit_sale:'.$sale->id.':post_cutover_cancellation',
                'profit_sale',
                (int) $sale->id,
                $sale->cancelled_at ?: now(),
                $sale->paymentBox?->currency ?: 'شيكل',
                'إلغاء بيع ربحي سابق للتهيئة #'.$sale->id,
                $lines,
                ['post_cutover_event' => true],
                $sale->created_by ?: auth()->id(),
            );
        }

        if ($sale->isCancelled()) {
            return $this->accounting->reverse('profit_sale', (int) $sale->id, $sale->cancelled_at ?: now(), 'عكس بيع ربحي ملغى', auth()->id());
        }
        $sale->loadMissing('paymentBox');
        $total = round(max(0, (float) $sale->total_cost), 4);
        if ($total <= 0) {
            return null;
        }
        $paid = min($total, round(max(0, (float) ($sale->payment_box_value ?? 0)), 4));
        $lines = [];
        if ($paid > 0) {
            $lines[] = $this->line('cash', $paid, 0, $sale, ['box_id' => $sale->payment_box_id]);
        }
        if ($total - $paid > 0) {
            $entity = ['customer_id' => $sale->customer_id, 'seller_id' => $sale->seller_id];
            $lines = array_merge($lines, $this->partyEffectLines(
                $sale,
                $entity,
                $total - $paid,
                'given',
                $this->partyBalanceBeforeSource(
                    $sale->customer_id,
                    $sale->seller_id,
                    $sale->paymentBox?->currency,
                    'profit_sale',
                    (int) $sale->id,
                ),
            ));
        }
        $lines[] = $this->line($this->profitSaleRevenueAccount($sale), 0, $total, $sale);

        return $this->accounting->post(
            'profit_sale:'.$sale->id,
            'profit_sale',
            (int) $sale->id,
            $sale->created_at ?: now(),
            $sale->paymentBox?->currency ?: 'شيكل',
            'بيع ربحي #'.$sale->id,
            $lines,
            [],
            $sale->created_by ?: auth()->id(),
        );
    }

    private function syncExpense(Expense $expense): ?AccountingJournalEntry
    {
        if ($expense->payment_method === 'carrier_withholding'
            && Schema::hasTable('sales_order_settlements')
            && Schema::hasColumn('sales_order_settlements', 'carrier_fee_expense_id')
            && DB::table('sales_order_settlements')->where('carrier_fee_expense_id', $expense->id)->exists()) {
            return null;
        }
        $expense->loadMissing('box');
        $amount = round(max(0, (float) $expense->price), 4);
        if ($amount <= 0) {
            return null;
        }
        $account = match ($expense->expense_type) {
            'salary' => 'salary_expense',
            'destruction' => 'inventory_loss',
            default => $expense->payment_method === 'carrier_withholding' ? 'delivery_expense' : 'general_expense',
        };
        $credit = $expense->expense_type === 'salary'
            ? 'salary_payable'
            : ($expense->box_id ? 'cash' : 'clearing');

        return $this->accounting->post(
            'expense:'.$expense->id,
            'expense',
            (int) $expense->id,
            $expense->expense_date ?: $expense->created_at ?: now(),
            $expense->box?->currency ?: 'شيكل',
            'مصروف: '.($expense->name ?: '#'.$expense->id),
            [
                $this->line($account, $amount, 0, $expense),
                $this->line($credit, 0, $amount, $expense, ['box_id' => $expense->box_id]),
            ],
            ['expense_type' => $expense->expense_type, 'payment_method' => $expense->payment_method],
            $expense->created_by_user_id ?: auth()->id(),
        );
    }

    private function syncEmployeeAdvance(EmployeeOrder $order): ?AccountingJournalEntry
    {
        if ($this->createdBeforeCutover($order)) {
            return $this->syncLegacyEmployeeAdvanceEvent($order);
        }
        if ($order->type !== 'loan' || ! in_array($order->status, ['approved', 'paid'], true) || $order->cancelled_at) {
            return $this->accounting->reverse('employee_advance', (int) $order->id, $order->cancelled_at ?: now(), 'عكس سلفة موظف غير فعالة', auth()->id());
        }
        $order->loadMissing('approvedBox');
        $amount = round(max(0, (float) $order->loan_value), 4);
        if ($amount <= 0) {
            return null;
        }

        return $this->accounting->post(
            'employee_advance:'.$order->id,
            'employee_advance',
            (int) $order->id,
            $order->created_at ?: now(),
            $order->approvedBox?->currency ?: 'شيكل',
            'صرف سلفة للموظف #'.$order->employee_id,
            [
                $this->line('employee_advances', $amount, 0, $order, ['metadata' => ['employee_id' => $order->employee_id]]),
                $this->line($order->approved_box_id ? 'cash' : 'clearing', 0, $amount, $order, ['box_id' => $order->approved_box_id]),
            ],
            ['employee_id' => $order->employee_id],
            auth()->id(),
        );
    }

    private function syncLegacyEmployeeAdvanceEvent(EmployeeOrder $order): ?AccountingJournalEntry
    {
        if (! $this->happenedAfterCutover($order->updated_at) || $order->type !== 'loan') {
            return null;
        }
        $cutover = DB::table('accounting_cutovers')->where('status', 'applied')->latest('id')->first();
        if (! $cutover) {
            return null;
        }
        $openingAccountId = DB::table('accounting_accounts')->where('system_key', 'employee_advances')->value('id');
        $opening = (float) DB::table('accounting_journal_lines as lines')
            ->join('accounting_journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->where('entries.source_type', 'accounting_cutover')
            ->where('lines.account_id', $openingAccountId)
            ->where('lines.metadata->employee_order_id', $order->id)
            ->sum(DB::raw('lines.debit - lines.credit'));
        $preCutoverApplications = Schema::hasTable('employee_advance_applications')
            ? (float) DB::table('employee_advance_applications')
                ->where('employee_order_id', $order->id)
                ->where('created_at', '<=', $cutover->applied_at)
                ->sum('amount')
            : 0.0;
        $active = in_array($order->status, ['approved', 'paid'], true) && ! $order->cancelled_at;
        $desiredBase = $active ? max(0, (float) $order->loan_value - $preCutoverApplications) : 0.0;
        $delta = round($desiredBase - $opening, 4);
        if (abs($delta) <= 0.0001) {
            return null;
        }
        $order->loadMissing('approvedBox');
        $amount = abs($delta);
        $lines = $delta > 0
            ? [
                $this->line('employee_advances', $amount, 0, $order),
                $this->line($order->approved_box_id ? 'cash' : 'clearing', 0, $amount, $order, ['box_id' => $order->approved_box_id]),
            ]
            : [
                $this->line($order->approved_box_id ? 'cash' : 'clearing', $amount, 0, $order, ['box_id' => $order->approved_box_id]),
                $this->line('employee_advances', 0, $amount, $order),
            ];

        return $this->accounting->post(
            'employee_advance:'.$order->id.':post_cutover_delta',
            'employee_advance',
            (int) $order->id,
            $order->updated_at ?: now(),
            $order->approvedBox?->currency ?: 'شيكل',
            'تغير لاحق على سلفة افتتاحية للموظف #'.$order->employee_id,
            $lines,
            ['post_cutover_event' => true, 'opening_balance' => $opening, 'delta' => $delta],
            auth()->id(),
        );
    }

    private function syncEmployeeAdvanceApplication(EmployeeAdvanceApplication $application): ?AccountingJournalEntry
    {
        $application->loadMissing(['order', 'salaryPeriod']);
        $amount = round(max(0, (float) $application->amount), 4);
        if ($amount <= 0) {
            return null;
        }

        return $this->accounting->post(
            'employee_advance_application:'.$application->id,
            'employee_advance_application',
            (int) $application->id,
            $application->created_at ?: now(),
            'شيكل',
            'تسوية سلفة من راتب الموظف #'.($application->order?->employee_id ?: ''),
            [
                $this->line('salary_payable', $amount, 0, $application),
                $this->line('employee_advances', 0, $amount, $application),
            ],
            ['employee_order_id' => $application->employee_order_id, 'salary_period_id' => $application->salary_period_id],
            auth()->id(),
        );
    }

    private function syncSalaryPayment(SalaryPaymentItem $payment): ?AccountingJournalEntry
    {
        $payment->loadMissing(['batch.box', 'salaryPeriod']);
        $amount = round(max(0, (float) $payment->amount_paid), 4);
        if ($amount <= 0) {
            return null;
        }

        return $this->accounting->post(
            'salary_payment:'.$payment->id,
            'salary_payment',
            (int) $payment->id,
            $payment->batch?->payment_date ?: $payment->created_at ?: now(),
            $payment->batch?->box?->currency ?: 'شيكل',
            'دفع راتب للموظف #'.$payment->employee_id,
            [
                $this->line('salary_payable', $amount, 0, $payment),
                $this->line($payment->batch?->box_id ? 'cash' : 'clearing', 0, $amount, $payment, ['box_id' => $payment->batch?->box_id]),
            ],
            ['salary_period_id' => $payment->salary_period_id, 'employee_id' => $payment->employee_id],
            $payment->batch?->created_by_user_id ?: auth()->id(),
        );
    }

    private function syncSalesReturn(SalesReturn $return): ?AccountingJournalEntry
    {
        if (! in_array($return->return_type, ['direct', 'partial'], true) || $return->status !== 'completed' || $return->cancelled_at) {
            return $this->accounting->reverse('sales_return', (int) $return->id, $return->cancelled_at ?: now(), 'عكس مردود مبيعات غير فعال', auth()->id());
        }
        $return->loadMissing(['items', 'refundBox', 'salesOrder']);
        $missingCost = $return->items->first(fn ($item) => (float) $item->quantity > 0 && $item->inventory_total_cost === null);
        if ($missingCost) {
            throw new RuntimeException('Missing FIFO cost snapshot for sales return item '.$missingCost->id.'.');
        }
        $total = round(max(0, (float) $return->total_amount), 4);
        $cash = min($total, round(max(0, (float) $return->cash_refund_amount), 4));
        $carrierCredit = min($total - $cash, round(max(0, (float) $return->carrier_credit_amount), 4));
        $credit = min($total - $cash - $carrierCredit, round(max(0, (float) $return->credit_amount), 4));
        $unallocated = round(max(0, $total - $cash - $carrierCredit - $credit), 4);
        $cost = round((float) $return->items->sum('inventory_total_cost'), 4);
        $lines = [$this->line('sales_returns', $total, 0, $return)];
        if ($cash > 0) {
            $lines[] = $this->line('cash', 0, $cash, $return, ['box_id' => $return->refund_box_id]);
        }
        if ($credit > 0) {
            $entity = ['customer_id' => $return->customer_id, 'seller_id' => $return->seller_id];
            $lines = array_merge($lines, $this->partyEffectLines(
                $return,
                $entity,
                $credit,
                'taken',
                $this->partyBalanceBeforeSource(
                    $return->customer_id,
                    $return->seller_id,
                    $return->currency,
                    'sales_return',
                    (int) $return->id,
                    'sales_return',
                    (int) $return->id,
                    $return->debt_transaction_id,
                ),
            ));
        }
        if ($carrierCredit > 0) {
            if (! $return->salesOrder?->delivery_company_id) {
                throw new RuntimeException('Sales return '.$return->id.' has carrier credit without delivery company.');
            }
            $lines[] = $this->line('accounts_receivable', 0, $carrierCredit, $return, [
                'delivery_company_id' => $return->salesOrder->delivery_company_id,
            ]);
        }
        if ($unallocated > 0) {
            $lines[] = $this->line('clearing', 0, $unallocated, $return);
        }
        if ($cost > 0) {
            $lines[] = $this->line('inventory', $cost, 0, $return);
            $lines[] = $this->line('cost_of_goods_sold', 0, $cost, $return);
        }

        return $this->accounting->post(
            'sales_return:'.$return->id,
            'sales_return',
            (int) $return->id,
            $return->completed_at ?: $return->created_at ?: now(),
            $return->currency ?: $return->refundBox?->currency ?: 'شيكل',
            'مردود مبيعات '.($return->serial_number ?: '#'.$return->id),
            $lines,
            ['cost_coverage_complete' => true],
            $return->created_by ?: auth()->id(),
        );
    }

    private function syncPurchaseReceipt(?PurchaseReceipt $receipt): ?AccountingJournalEntry
    {
        if (! $receipt) {
            return null;
        }
        $receipt->loadMissing(['items', 'bill']);
        $amount = round((float) $receipt->items->sum(fn ($item) => (float) $item->accepted_quantity * (float) $item->unit_price), 4);
        if ($amount <= 0) {
            return null;
        }
        $entity = ['seller_id' => $receipt->bill?->seller_id, 'customer_id' => $receipt->bill?->customer_id];
        $payableLines = $this->partyEffectLines(
            $receipt,
            $entity,
            $amount,
            'taken',
            $this->partyBalanceBeforeSource(
                $entity['customer_id'],
                $entity['seller_id'],
                $receipt->bill?->currency,
                'purchase_invoice',
                (int) $receipt->bill_id,
                'purchase_receipt',
                (int) $receipt->id,
            ),
        );

        return $this->accounting->post(
            'purchase_receipt:'.$receipt->id,
            'purchase_receipt',
            (int) $receipt->id,
            $receipt->received_at ?: $receipt->created_at ?: now(),
            $receipt->bill?->currency ?: 'شيكل',
            'استلام مشتريات '.($receipt->receipt_number ?: '#'.$receipt->id),
            array_merge([
                $this->line('inventory', $amount, 0, $receipt, $entity),
            ], $payableLines),
            ['bill_id' => $receipt->bill_id],
            $receipt->created_by ?: auth()->id(),
        );
    }

    private function syncPurchasePayment(PurchasePayment $payment): ?AccountingJournalEntry
    {
        $payment->loadMissing(['box']);
        $amount = round(max(0, (float) $payment->amount), 4);
        if ($amount <= 0) {
            return null;
        }
        $entity = ['seller_id' => $payment->seller_id, 'customer_id' => $payment->customer_id];
        $debtSource = $payment->type === 'initial_payment' ? 'purchase_initial_payment' : 'purchase_payment';
        $paymentPartyLines = $this->partyEffectLines(
            $payment,
            $entity,
            $amount,
            'given',
            $this->partyBalanceBeforeSource(
                $payment->customer_id,
                $payment->seller_id,
                $payment->currency ?: $payment->box?->currency,
                $debtSource,
                (int) $payment->bill_id,
                'purchase_payment',
                (int) $payment->id,
                $payment->debt_transaction_id,
            ),
        );

        return $this->accounting->post(
            'purchase_payment:'.$payment->id,
            'purchase_payment',
            (int) $payment->id,
            $payment->paid_at ?: $payment->created_at ?: now(),
            $payment->currency ?: $payment->box?->currency ?: 'شيكل',
            'دفعة مشتريات #'.$payment->id,
            array_merge($paymentPartyLines, [
                $this->line($payment->box_id ? 'cash' : 'clearing', 0, $amount, $payment, array_merge($entity, ['box_id' => $payment->box_id])),
            ]),
            ['bill_id' => $payment->bill_id, 'payment_type' => $payment->type],
            $payment->created_by ?: auth()->id(),
        );
    }

    private function syncPurchaseReturn(ReturnModel $return): ?AccountingJournalEntry
    {
        if (! in_array($return->status, ['delivered', 'settled'], true) || $return->cancelled_at) {
            return $this->accounting->reverse('purchase_return', (int) $return->id, $return->cancelled_at ?: now(), 'عكس مردود مشتريات غير فعال', auth()->id());
        }
        $return->loadMissing(['items', 'refundBox']);
        $costInspection = $this->inventoryIntegrity->assertPurchaseReturnReady($return);
        $inventory = round((float) $costInspection['total_cost'], 4);
        $settlementAmount = round(max(0, (float) $return->total), 4);
        if (abs($settlementAmount - $inventory) > 0.0001) {
            throw new RuntimeException('Purchase return '.$return->id.' settlement amount does not match its verified FIFO inventory cost; variance requires review.');
        }
        $entity = ['seller_id' => $return->seller_id, 'customer_id' => $return->customer_id];
        $debitLines = $return->resolution === 'cash_refund' && $return->refund_box_id
            ? [$this->line('cash', $inventory, 0, $return, array_merge($entity, ['box_id' => $return->refund_box_id]))]
            : $this->partyEffectLines(
                $return,
                $entity,
                $inventory,
                'given',
                $this->partyBalanceBeforeSource(
                    $return->customer_id,
                    $return->seller_id,
                    $return->currency,
                    'purchase_return',
                    (int) $return->id,
                    'purchase_return',
                    (int) $return->id,
                    $return->debt_transaction_id,
                ),
            );

        return $this->accounting->post(
            'purchase_return:'.$return->id,
            'purchase_return',
            (int) $return->id,
            $return->settled_at ?: $return->delivered_at ?: $return->created_at ?: now(),
            $return->currency ?: $return->refundBox?->currency ?: 'شيكل',
            'مردود مشتريات '.($return->number ?: '#'.$return->id),
            array_merge($debitLines, [
                $this->line('inventory', 0, $inventory, $return, $entity),
            ]),
            ['resolution' => $return->resolution, 'bill_id' => $return->bill_id],
            $return->created_by ?: auth()->id(),
        );
    }

    private function syncAsset(Asset $asset): ?AccountingJournalEntry
    {
        $asset->loadMissing('box');
        $amount = round(max(0, (float) $asset->price), 4);
        if ($amount <= 0) {
            return null;
        }

        return $this->accounting->post(
            'asset:'.$asset->id,
            'asset',
            (int) $asset->id,
            $asset->acquired_at ?: $asset->created_at ?: now(),
            $asset->currency ?: $asset->box?->currency ?: 'شيكل',
            'إثبات أصل: '.($asset->name ?: '#'.$asset->id),
            [
                $this->line('fixed_assets', $amount, 0, $asset),
                $this->line($asset->box_id ? 'cash' : 'clearing', 0, $amount, $asset, ['box_id' => $asset->box_id]),
            ],
            ['funding_source_required' => ! $asset->box_id],
            auth()->id(),
        );
    }

    private function syncAssetLog(AssetLog $log): ?AccountingJournalEntry
    {
        if ($log->type !== 'depreciate' || (float) $log->depreciation_amount <= 0) {
            return null;
        }
        $log->loadMissing('asset');
        $amount = round((float) $log->depreciation_amount, 4);

        return $this->accounting->post(
            'asset_depreciation:'.$log->id,
            'asset_depreciation',
            (int) $log->id,
            ($log->depreciation_period ?: now()->format('Y-m')).'-01',
            $log->asset?->currency ?: 'شيكل',
            'إهلاك أصل #'.$log->asset_id.' للفترة '.$log->depreciation_period,
            [
                $this->line('depreciation_expense', $amount, 0, $log),
                $this->line('accumulated_depreciation', 0, $amount, $log),
            ],
            ['asset_id' => $log->asset_id, 'period' => $log->depreciation_period],
            $log->processed_by_user_id ?: auth()->id(),
        );
    }

    private function syncProjectExpense(ProjectExpense $expense): ?AccountingJournalEntry
    {
        $expense->loadMissing('box');
        $amount = round(max(0, (float) $expense->expenses), 4);
        if ($amount <= 0) {
            return null;
        }

        return $this->accounting->post(
            'project_expense:'.$expense->id,
            'project_expense',
            (int) $expense->id,
            $expense->expense_date ?: $expense->created_at ?: now(),
            $expense->currency ?: $expense->box?->currency ?: 'شيكل',
            'مصروف مشروع #'.$expense->project_id,
            [
                $this->line('project_expense', $amount, 0, $expense),
                $this->line($expense->box_id ? 'cash' : 'clearing', 0, $amount, $expense, ['box_id' => $expense->box_id]),
            ],
            ['project_id' => $expense->project_id, 'payment_source_required' => ! $expense->box_id],
            $expense->created_by ?: auth()->id(),
        );
    }

    private function syncIncomingCheck(IncomingCheck $check): ?AccountingJournalEntry
    {
        $amount = round(max(0, (float) $check->total), 4);
        if ($amount <= 0) {
            return null;
        }
        if ($this->createdBeforeCutover($check)) {
            return $this->syncLegacyIncomingCheckEvent($check, $amount);
        }
        if (in_array($check->status, ['cancelled', 'returned'], true)) {
            $this->accounting->reverseAll('incoming_check', (int) $check->id, $check->updated_at ?: now(), 'عكس شيك وارد ملغي أو مرتجع', auth()->id());

            return null;
        }

        $receivedEntity = ['customer_id' => $check->from_customer, 'seller_id' => $check->from_seller];
        $receiptPartyLines = $this->partyEffectLines(
            $check,
            $receivedEntity,
            $amount,
            'taken',
            $this->partyBalanceBeforeSource(
                $check->from_customer,
                $check->from_seller,
                $check->currency,
                'incoming_check',
                (int) $check->id,
            ),
        );
        $receipt = $this->accounting->post(
            'incoming_check:'.$check->id.':receipt',
            'incoming_check',
            (int) $check->id,
            $check->received_at ?: $check->created_at ?: now(),
            $check->currency ?: 'شيكل',
            'استلام شيك وارد '.($check->check_id ?: '#'.$check->id),
            array_merge([
                $this->line('checks_receivable', $amount, 0, $check, array_merge($receivedEntity, ['due_date' => $check->due_date])),
            ], $receiptPartyLines),
            ['status' => $check->status, 'stage' => 'receipt'],
            auth()->id(),
        );

        $disposalPrefix = 'incoming_check:'.$check->id.':disposal:';
        if (! in_array($check->status, ['cashed_to_box', 'cashed_to_person', 'cashed'], true)) {
            $this->accounting->reverseBySourceKeyPrefix($disposalPrefix, $check->updated_at ?: now(), 'عكس تصرف سابق في الشيك الوارد', auth()->id());

            return $receipt;
        }

        $currentKey = $disposalPrefix.$check->status;
        $activeOther = AccountingJournalEntry::query()
            ->where('source_type', 'incoming_check')
            ->where('source_id', $check->id)
            ->where('source_key', 'like', $disposalPrefix.'%')
            ->where('source_key', '!=', $currentKey)
            ->where('status', AccountingJournalEntry::STATUS_POSTED)
            ->whereNull('reverses_entry_id')
            ->exists();
        if ($activeOther) {
            $this->accounting->reverseBySourceKeyPrefix($disposalPrefix, $check->updated_at ?: now(), 'عكس تصرف سابق في الشيك الوارد', auth()->id());
        }

        if ($check->status === 'cashed_to_person') {
            $targetEntity = ['customer_id' => $check->to_customer, 'seller_id' => $check->to_seller];
            $disposalDebitLines = $this->partyEffectLines(
                $check,
                $targetEntity,
                $amount,
                'given',
                $this->partyBalanceBeforeSource(
                    $check->to_customer,
                    $check->to_seller,
                    $check->currency,
                    'incoming_check_disposal',
                    (int) $check->id,
                ),
            );
        } else {
            $boxId = $check->boxes()->latest('id')->value('box_id');
            $disposalDebitLines = [$this->line('cash', $amount, 0, $check, ['box_id' => $boxId])];
        }

        return $this->accounting->post(
            $currentKey,
            'incoming_check',
            (int) $check->id,
            $check->updated_at ?: now(),
            $check->currency ?: 'شيكل',
            'تصرف في شيك وارد '.($check->check_id ?: '#'.$check->id),
            array_merge($disposalDebitLines, [
                $this->line('checks_receivable', 0, $amount, $check, array_merge($receivedEntity, ['due_date' => $check->due_date])),
            ]),
            ['status' => $check->status, 'stage' => 'disposal'],
            auth()->id(),
        );
    }

    private function syncOutgoingCheck(OutgoingCheck $check): ?AccountingJournalEntry
    {
        $amount = round(max(0, (float) $check->total), 4);
        if ($amount <= 0) {
            return null;
        }
        if ($this->createdBeforeCutover($check)) {
            return $this->syncLegacyOutgoingCheckEvent($check, $amount);
        }
        if (in_array($check->status, ['cancelled', 'returned'], true)) {
            $this->accounting->reverseAll('outgoing_check', (int) $check->id, $check->updated_at ?: now(), 'عكس شيك صادر ملغي أو مرتجع', auth()->id());

            return null;
        }

        $entity = ['customer_id' => $check->customer_id, 'seller_id' => $check->seller_id];
        $issuePartyLines = $this->partyEffectLines(
            $check,
            $entity,
            $amount,
            'given',
            $this->partyBalanceBeforeSource(
                $check->customer_id,
                $check->seller_id,
                $check->currency,
                'outgoing_check',
                (int) $check->id,
            ),
        );
        $issue = $this->accounting->post(
            'outgoing_check:'.$check->id.':issue',
            'outgoing_check',
            (int) $check->id,
            $check->created_at ?: now(),
            $check->currency ?: 'شيكل',
            'إصدار شيك '.($check->check_id ?: '#'.$check->id),
            array_merge($issuePartyLines, [
                $this->line('checks_payable', 0, $amount, $check, array_merge($entity, ['due_date' => $check->due_date])),
            ]),
            ['status' => $check->status, 'stage' => 'issue'],
            auth()->id(),
        );

        $settlementPrefix = 'outgoing_check:'.$check->id.':settlement:';
        if (! in_array($check->status, ['cashed_from_box', 'cashed'], true)) {
            $this->accounting->reverseBySourceKeyPrefix($settlementPrefix, $check->updated_at ?: now(), 'عكس صرف سابق للشيك الصادر', auth()->id());

            return $issue;
        }

        return $this->accounting->post(
            $settlementPrefix.$check->status,
            'outgoing_check',
            (int) $check->id,
            $check->updated_at ?: now(),
            $check->currency ?: 'شيكل',
            'صرف شيك صادر '.($check->check_id ?: '#'.$check->id),
            [
                $this->line('checks_payable', $amount, 0, $check, array_merge($entity, ['due_date' => $check->due_date])),
                $this->line('cash', 0, $amount, $check, array_merge($entity, ['box_id' => $check->box_id])),
            ],
            ['status' => $check->status, 'stage' => 'settlement'],
            auth()->id(),
        );
    }

    private function syncInventoryAdjustment(InventoryAdjustment $adjustment): ?AccountingJournalEntry
    {
        $difference = round((float) $adjustment->value_difference, 4);
        if (abs($difference) <= 0.0001) {
            return null;
        }

        $entity = ['product_id' => $adjustment->product_id];
        if ($difference > 0) {
            $creditAccount = match ($adjustment->adjustment_type) {
                InventoryAdjustment::TYPE_COST_INITIALIZATION => 'opening_balance',
                InventoryAdjustment::TYPE_COST_REVALUATION => 'inventory_revaluation_reserve',
                default => 'inventory_gain',
            };
            $lines = [
                $this->line('inventory', $difference, 0, $adjustment, $entity),
                $this->line($creditAccount, 0, $difference, $adjustment, $entity),
            ];
        } else {
            $amount = abs($difference);
            $lines = [
                $this->line('inventory_loss', $amount, 0, $adjustment, $entity),
                $this->line('inventory', 0, $amount, $adjustment, $entity),
            ];
        }

        return $this->accounting->post(
            'inventory_adjustment:'.$adjustment->id,
            'inventory_adjustment',
            (int) $adjustment->id,
            $adjustment->created_at ?: now(),
            $adjustment->currency ?: 'شيكل',
            'تسوية مخزون '.$adjustment->reference,
            $lines,
            ['adjustment_type' => $adjustment->adjustment_type, 'reason' => $adjustment->reason],
            $adjustment->created_by ?: auth()->id(),
        );
    }

    private function syncLegacyInstantSaleCancellation(InstantSale $sale): ?AccountingJournalEntry
    {
        if (! $sale->isCancelled() || ! $this->happenedAfterCutover($sale->cancelled_at ?: $sale->updated_at)) {
            return null;
        }
        $sale->loadMissing(['subProducts', 'paymentBox']);
        $saleLines = collect([$sale])->concat($sale->subProducts);
        $missingCost = $saleLines->first(fn (InstantSale $line) => (float) ($line->quantity ?? 0) > 0 && $line->inventory_total_cost === null);
        if ($missingCost) {
            throw new RuntimeException('Missing FIFO cost snapshot for post-cutover cancellation line '.$missingCost->id.'.');
        }
        $total = round(max(0, (float) $sale->total_cost), 4);
        $paid = min($total, round(max(0, (float) $sale->payment_box_value), 4));
        $cost = round((float) $saleLines->sum(fn (InstantSale $line) => (float) $line->inventory_total_cost), 4);
        $journal = [$this->line('sales_returns', $total, 0, $sale)];
        if ($paid > 0) {
            $journal[] = $this->line('cash', 0, $paid, $sale, ['box_id' => $sale->payment_box_id]);
        }
        if ($total - $paid > 0) {
            $journal[] = $this->line('accounts_receivable', 0, $total - $paid, $sale);
        }
        if ($cost > 0) {
            $journal[] = $this->line('inventory', $cost, 0, $sale);
            $journal[] = $this->line('cost_of_goods_sold', 0, $cost, $sale);
        }

        return $this->accounting->post(
            'instant_sale:'.$sale->id.':post_cutover_cancellation',
            'instant_sale',
            (int) $sale->id,
            $sale->cancelled_at ?: now(),
            $sale->paymentBox?->currency ?: 'شيكل',
            'إلغاء فاتورة سابقة للتهيئة '.($sale->serial_number ?: '#'.$sale->id),
            $journal,
            ['post_cutover_event' => true, 'cost_coverage_complete' => true],
            $sale->created_by ?: auth()->id(),
        );
    }

    private function syncLegacyIncomingCheckEvent(IncomingCheck $check, float $amount): ?AccountingJournalEntry
    {
        if (! $this->happenedAfterCutover($check->updated_at)) {
            return null;
        }
        $from = ['customer_id' => $check->from_customer, 'seller_id' => $check->from_seller];
        $sourceKey = 'incoming_check:'.$check->id.':post_cutover_'.$check->status;
        $this->accounting->reverseOtherBySourceKeyPrefix(
            'incoming_check:'.$check->id.':post_cutover_',
            $sourceKey,
            $check->updated_at ?: now(),
            'عكس الحالة السابقة لشيك وارد افتتاحي',
            auth()->id(),
        );
        if (in_array($check->status, ['cancelled', 'returned'], true)) {
            $cancellationLines = $this->partyEffectLines(
                $check,
                $from,
                $amount,
                'taken',
                $this->partyBalanceBeforeSource(
                    $check->from_customer,
                    $check->from_seller,
                    $check->currency,
                    'incoming_check',
                    (int) $check->id,
                ),
                reverse: true,
            );

            return $this->accounting->post(
                $sourceKey,
                'incoming_check',
                (int) $check->id,
                $check->updated_at ?: now(),
                $check->currency ?: 'شيكل',
                'إلغاء أو إرجاع شيك وارد افتتاحي '.$check->check_id,
                array_merge($cancellationLines, [
                    $this->line('checks_receivable', 0, $amount, $check, array_merge($from, ['due_date' => $check->due_date])),
                ]),
                ['post_cutover_event' => true, 'status' => $check->status],
                auth()->id(),
            );
        }
        if (! in_array($check->status, ['cashed_to_box', 'cashed_to_person', 'cashed'], true)) {
            return null;
        }
        if ($check->status === 'cashed_to_person') {
            $target = ['customer_id' => $check->to_customer, 'seller_id' => $check->to_seller];
            $debitLines = $this->partyEffectLines(
                $check,
                $target,
                $amount,
                'given',
                $this->partyBalanceBeforeSource(
                    $check->to_customer,
                    $check->to_seller,
                    $check->currency,
                    'incoming_check_disposal',
                    (int) $check->id,
                ),
            );
        } else {
            $target = ['box_id' => $check->boxes()->latest('id')->value('box_id')];
            $debitLines = [$this->line('cash', $amount, 0, $check, $target)];
        }

        return $this->accounting->post(
            $sourceKey,
            'incoming_check',
            (int) $check->id,
            $check->updated_at ?: now(),
            $check->currency ?: 'شيكل',
            'تصرف في شيك وارد افتتاحي '.$check->check_id,
            array_merge($debitLines, [
                $this->line('checks_receivable', 0, $amount, $check, array_merge($from, ['due_date' => $check->due_date])),
            ]),
            ['post_cutover_event' => true, 'status' => $check->status],
            auth()->id(),
        );
    }

    private function syncLegacyOutgoingCheckEvent(OutgoingCheck $check, float $amount): ?AccountingJournalEntry
    {
        if (! $this->happenedAfterCutover($check->updated_at)) {
            return null;
        }
        $entity = ['customer_id' => $check->customer_id, 'seller_id' => $check->seller_id];
        $sourceKey = 'outgoing_check:'.$check->id.':post_cutover_'.$check->status;
        $this->accounting->reverseOtherBySourceKeyPrefix(
            'outgoing_check:'.$check->id.':post_cutover_',
            $sourceKey,
            $check->updated_at ?: now(),
            'عكس الحالة السابقة لشيك صادر افتتاحي',
            auth()->id(),
        );
        if (in_array($check->status, ['cancelled', 'returned'], true)) {
            $cancellationLines = $this->partyEffectLines(
                $check,
                $entity,
                $amount,
                'given',
                $this->partyBalanceBeforeSource(
                    $check->customer_id,
                    $check->seller_id,
                    $check->currency,
                    'outgoing_check',
                    (int) $check->id,
                ),
                reverse: true,
            );

            return $this->accounting->post(
                $sourceKey,
                'outgoing_check',
                (int) $check->id,
                $check->updated_at ?: now(),
                $check->currency ?: 'شيكل',
                'إلغاء أو إرجاع شيك صادر افتتاحي '.$check->check_id,
                [
                    $this->line('checks_payable', $amount, 0, $check, array_merge($entity, ['due_date' => $check->due_date])),
                    ...$cancellationLines,
                ],
                ['post_cutover_event' => true, 'status' => $check->status],
                auth()->id(),
            );
        }
        if (! in_array($check->status, ['cashed_from_box', 'cashed'], true)) {
            return null;
        }

        return $this->accounting->post(
            $sourceKey,
            'outgoing_check',
            (int) $check->id,
            $check->updated_at ?: now(),
            $check->currency ?: 'شيكل',
            'صرف شيك صادر افتتاحي '.$check->check_id,
            [
                $this->line('checks_payable', $amount, 0, $check, array_merge($entity, ['due_date' => $check->due_date])),
                $this->line('cash', 0, $amount, $check, array_merge($entity, ['box_id' => $check->box_id])),
            ],
            ['post_cutover_event' => true, 'status' => $check->status],
            auth()->id(),
        );
    }

    private function syncBoxLog(BoxLog $log): ?AccountingJournalEntry
    {
        $amount = round(abs((float) ($log->value ?? 0)), 4);
        if ($amount <= 0) {
            return null;
        }
        if ($log->type !== 'transfer') {
            if (! in_array($log->description, ['تم اضافة رصيد للصندوق', 'تم سحب رصيد من الصندوق'], true)) {
                return null;
            }
            $log->loadMissing('box');
            $isIncrease = $log->description === 'تم اضافة رصيد للصندوق';

            return $this->accounting->post(
                'box_adjustment:'.$log->id,
                'box_adjustment',
                (int) $log->id,
                $log->created_at ?: now(),
                $log->box?->currency ?: 'شيكل',
                $log->description,
                $isIncrease
                    ? [
                        $this->line('cash', $amount, 0, $log, ['box_id' => $log->box_id]),
                        $this->line('owner_equity', 0, $amount, $log),
                    ]
                    : [
                        $this->line('owner_equity', $amount, 0, $log),
                        $this->line('cash', 0, $amount, $log, ['box_id' => $log->box_id]),
                    ],
                ['manual_box_balance_adjustment' => true, 'note' => $log->note],
                auth()->id(),
            );
        }
        if (! $log->from_box_id || ! $log->to_box_id) {
            return null;
        }
        $log->loadMissing(['fromBox', 'toBox']);
        $currency = $log->fromBox?->currency ?: $log->toBox?->currency ?: 'شيكل';

        return $this->accounting->post(
            'box_transfer:'.$log->id,
            'box_transfer',
            (int) $log->id,
            $log->created_at ?: now(),
            $currency,
            $log->description ?: 'تحويل بين صندوقين',
            [
                $this->line('cash', $amount, 0, $log, ['box_id' => $log->to_box_id]),
                $this->line('cash', 0, $amount, $log, ['box_id' => $log->from_box_id]),
            ],
            ['from_box_id' => $log->from_box_id, 'to_box_id' => $log->to_box_id],
            auth()->id(),
        );
    }

    private function syncSalesOrderSettlement(SalesOrderSettlement $settlement): ?AccountingJournalEntry
    {
        $settlement->loadMissing(['box', 'order']);

        if ($settlement->source === 'cancellation_reversal') {
            $settlement->order?->settlements()
                ->whereKeyNot($settlement->id)
                ->where('source', '!=', 'cancellation_reversal')
                ->each(function (SalesOrderSettlement $original) use ($settlement) {
                    $this->accounting->reverse(
                        'sales_order_settlement',
                        (int) $original->id,
                        $settlement->created_at ?: now(),
                        'عكس تحصيل طلبية ملغاة أو مرتجعة',
                        $settlement->created_by ?: auth()->id(),
                    );
                });

            return null;
        }

        if ($settlement->source === 'order_payment') {
            $cash = round(max(0, (float) ($settlement->cash_amount ?? $settlement->amount)), 4);
            if ($cash <= 0) {
                return null;
            }

            $creditLines = $settlement->order?->is_debt_collection
                ? $this->partyEffectLines(
                    $settlement,
                    ['customer_id' => $settlement->order?->customer_id, 'box_id' => $settlement->box_id],
                    $cash,
                    'taken',
                    $this->partyBalanceBeforeSource(
                        $settlement->order?->customer_id,
                        null,
                        $settlement->box?->currency,
                        'sales_order',
                        (int) $settlement->sales_order_id,
                    ),
                )
                : [$this->line('customer_deposits', 0, $cash, $settlement, [
                    'customer_id' => $settlement->order?->customer_id,
                ])];

            return $this->accounting->post(
                'sales_order_settlement:'.$settlement->id,
                'sales_order_settlement',
                (int) $settlement->id,
                $settlement->created_at ?: now(),
                $settlement->box?->currency ?: 'شيكل',
                'دفعة مقدمة لطلبية #'.$settlement->sales_order_id,
                array_merge([
                    $this->line('cash', $cash, 0, $settlement, [
                        'customer_id' => $settlement->order?->customer_id,
                        'box_id' => $settlement->box_id,
                    ]),
                ], $creditLines),
                ['sales_order_id' => $settlement->sales_order_id, 'source' => $settlement->source],
                $settlement->created_by ?: auth()->id(),
            );
        }

        $cash = round(max(0, (float) ($settlement->cash_amount ?? ((float) $settlement->amount - (float) $settlement->carrier_fee))), 4);
        $carrier = round(max(0, (float) $settlement->carrier_fee), 4);
        $total = round($cash + $carrier, 4);
        if ($total <= 0) {
            return null;
        }
        $entity = ['customer_id' => $settlement->order?->customer_id, 'box_id' => $settlement->box_id];
        $lines = [];
        if ($cash > 0) {
            $lines[] = $this->line('cash', $cash, 0, $settlement, $entity);
        }
        if ($carrier > 0) {
            $lines[] = $this->line('delivery_expense', $carrier, 0, $settlement, $entity);
        }
        if ($settlement->source === 'carrier') {
            if (! $settlement->order?->delivery_company_id) {
                throw new RuntimeException('Carrier settlement '.$settlement->id.' has no delivery company.');
            }
            $lines[] = $this->line('accounts_receivable', 0, $total, $settlement, [
                'delivery_company_id' => $settlement->order?->delivery_company_id,
            ]);
        } else {
            $lines = array_merge($lines, $this->partyEffectLines(
                $settlement,
                $entity,
                $total,
                'taken',
                -max(0, (float) $settlement->customer_debt_before),
            ));
        }

        return $this->accounting->post(
            'sales_order_settlement:'.$settlement->id,
            'sales_order_settlement',
            (int) $settlement->id,
            $settlement->created_at ?: now(),
            $settlement->box?->currency ?: 'شيكل',
            'تحصيل طلبية #'.$settlement->sales_order_id,
            $lines,
            ['sales_order_id' => $settlement->sales_order_id, 'source' => $settlement->source],
            $settlement->created_by ?: auth()->id(),
        );
    }

    private function syncSalesOrder(SalesOrder $order): ?AccountingJournalEntry
    {
        $sale = InstantSale::query()
            ->where('sales_order_id', $order->id)
            ->whereNull('parent_id')
            ->latest('id')
            ->first();

        if ($sale) {
            return $this->syncInstantSale($sale);
        }

        if ($order->is_debt_collection) {
            return $this->accounting->reverse('sales_order', (int) $order->id, now(), 'طلب تحصيل دين لا يمثل إيراد بيع', auth()->id());
        }
        if (! $order->financial_posted_at) {
            return $this->accounting->reverse('sales_order', (int) $order->id, now(), 'طلبية لم يتحقق إيرادها بعد', auth()->id());
        }
        if ($order->status === 'canceled') {
            return $this->accounting->reverse('sales_order', (int) $order->id, $order->updated_at ?: now(), 'عكس طلبية ملغاة', auth()->id());
        }

        $order->loadMissing(['settlements', 'salesReturns']);
        $total = round(max(0, (float) $order->total), 4);
        if ($total <= 0) {
            return null;
        }

        $paid = min($total, round(max(0, (float) $order->payment_amount), 4));
        $depositTotal = (float) $order->settlements
            ->where('source', 'order_payment')
            ->sum(fn (SalesOrderSettlement $row) => max(0, (float) $row->cash_amount));
        $depositApplied = min($paid, round($depositTotal, 4));
        $cashAtRecognition = round($paid - $depositApplied, 4);
        $receivable = round($total - $paid, 4);

        $existing = AccountingJournalEntry::query()
            ->where('source_type', 'sales_order')
            ->where('source_id', $order->id)
            ->where('status', AccountingJournalEntry::STATUS_POSTED)
            ->whereNull('reverses_entry_id')
            ->latest('id')
            ->first();
        $lockedAllocation = is_array($existing?->metadata)
            ? ($existing->metadata['receivable_allocation'] ?? null)
            : null;
        if (is_array($lockedAllocation)) {
            $carrierReceivable = min($receivable, round(max(0, (float) ($lockedAllocation['carrier'] ?? 0)), 4));
            $customerReceivable = round($receivable - $carrierReceivable, 4);
        } else {
            $carrierReceivable = min($receivable, round(max(0, (float) $order->carrier_receivable_balance), 4));
            $customerReceivable = round($receivable - $carrierReceivable, 4);
        }

        $lines = [];
        if ($depositApplied > 0) {
            $lines[] = $this->line('customer_deposits', $depositApplied, 0, $order, ['customer_id' => $order->customer_id]);
        }
        if ($cashAtRecognition > 0) {
            $lines[] = $this->line('cash', $cashAtRecognition, 0, $order, ['box_id' => $order->payment_box_id]);
        }
        if ($customerReceivable > 0) {
            $lines = array_merge($lines, $this->partyEffectLines(
                $order,
                ['customer_id' => $order->customer_id],
                $customerReceivable,
                'given',
                $this->partyBalanceBeforeSource(
                    $order->customer_id,
                    null,
                    'شيكل',
                    'sales_order',
                    (int) $order->id,
                ),
            ));
        }
        if ($carrierReceivable > 0) {
            if (! $order->delivery_company_id) {
                throw new RuntimeException('Sales order '.$order->id.' has carrier receivable without delivery company.');
            }
            $lines[] = $this->line('accounts_receivable', $carrierReceivable, 0, $order, [
                'delivery_company_id' => $order->delivery_company_id,
            ]);
        }
        $lines[] = $this->line('sales_revenue', 0, $total, $order);

        $costInspection = $this->inventoryIntegrity->assertSalesOrderReady($order);
        $cost = round((float) $costInspection['total_cost'], 4);
        if ($cost > 0) {
            $lines[] = $this->line('cost_of_goods_sold', $cost, 0, $order);
            $lines[] = $this->line('inventory', 0, $cost, $order);
        }

        return $this->accounting->post(
            'sales_order:'.$order->id,
            'sales_order',
            (int) $order->id,
            $order->financial_posted_at,
            'شيكل',
            'إثبات طلبية '.($order->serial_number ?: '#'.$order->id),
            $lines,
            [
                'cost_coverage_complete' => true,
                'customer_deposit_applied' => $depositApplied,
                'receivable_allocation' => [
                    'customer' => $customerReceivable,
                    'carrier' => $carrierReceivable,
                ],
            ],
            $order->updated_by ?: $order->created_by ?: auth()->id(),
        );
    }

    private function syncDebtCashMovement(DebtTransaction $transaction): ?AccountingJournalEntry
    {
        if ($transaction->archived_at || $transaction->deleted_at || ! $transaction->box_id) {
            return $this->accounting->reverse('debt_transaction', (int) $transaction->id, now(), 'عكس حركة نقدية مؤرشفة من دفتر الديون', auth()->id());
        }
        if (! in_array($transaction->source ?: 'manual', ['manual', ''], true)) {
            return null;
        }
        $transaction->loadMissing('box');
        $amount = round(max(0, (float) $transaction->amount), 4);
        if ($amount <= 0) {
            return null;
        }
        $entity = [
            'customer_id' => $transaction->customer_id,
            'seller_id' => $transaction->seller_id,
            'box_id' => $transaction->box_id,
        ];
        $before = $transaction->type === 'taken'
            ? (float) $transaction->balance_after - $amount
            : (float) $transaction->balance_after + $amount;
        $partyLines = $this->partyEffectLines(
            $transaction,
            $entity,
            $amount,
            $transaction->type,
            $before,
        );
        $lines = $transaction->type === 'taken'
            ? array_merge([
                $this->line('cash', $amount, 0, $transaction, $entity),
            ], $partyLines)
            : array_merge($partyLines, [
                $this->line('cash', 0, $amount, $transaction, $entity),
            ]);

        return $this->accounting->post(
            'debt_transaction:'.$transaction->id,
            'debt_transaction',
            (int) $transaction->id,
            $transaction->transaction_date ?: $transaction->created_at ?: now(),
            $transaction->currency ?: $transaction->box?->currency ?: 'شيكل',
            'حركة نقدية من دفتر الديون #'.$transaction->id,
            $lines,
            ['requires_account_allocation' => ! $transaction->customer_id && ! $transaction->seller_id, 'transaction_type' => $transaction->type],
            $transaction->created_by ?: auth()->id(),
        );
    }

    /**
     * Split one debt-ledger effect between receivables and payables. The
     * operational signed balance is positive when we owe the party and
     * negative when the party owes us.
     *
     * @param  array<string, mixed>  $entity
     * @return array<int, array<string, mixed>>
     */
    private function partyEffectLines(
        Model $model,
        array $entity,
        float $amount,
        string $effect,
        float $balanceBefore,
        bool $reverse = false,
    ): array {
        if (! ($entity['customer_id'] ?? null) && ! ($entity['seller_id'] ?? null)) {
            $lines = [$effect === 'taken'
                ? $this->line('clearing', 0, $amount, $model, $entity)
                : $this->line('clearing', $amount, 0, $model, $entity)];
        } else {
            $allocation = DebtLedgerService::allocateSignedMovement($balanceBefore, $amount, $effect);
            $lines = [];
            if ($allocation['receivable_debit'] > 0.0001 || $allocation['receivable_credit'] > 0.0001) {
                $lines[] = $this->line(
                    'accounts_receivable',
                    $allocation['receivable_debit'],
                    $allocation['receivable_credit'],
                    $model,
                    $entity,
                );
            }
            if ($allocation['payable_debit'] > 0.0001 || $allocation['payable_credit'] > 0.0001) {
                $lines[] = $this->line(
                    'accounts_payable',
                    $allocation['payable_debit'],
                    $allocation['payable_credit'],
                    $model,
                    $entity,
                );
            }
        }

        if (! $reverse) {
            return $lines;
        }

        return collect($lines)->map(function (array $line) {
            [$line['debit'], $line['credit']] = [$line['credit'], $line['debit']];

            return $line;
        })->all();
    }

    private function partyBalanceBeforeSource(
        ?int $customerId,
        ?int $sellerId,
        ?string $currency,
        string $source,
        int $sourceId,
        ?string $accountingSourceType = null,
        ?int $accountingSourceId = null,
        ?int $debtTransactionId = null,
    ): float {
        if (! $customerId && ! $sellerId) {
            return 0.0;
        }

        $sourceTransaction = DebtTransaction::query()
            ->active()
            ->when(
                $debtTransactionId,
                fn ($query) => $query->whereKey($debtTransactionId),
                fn ($query) => $query->where('source', $source)->where('source_id', $sourceId)->latest('id'),
            )
            ->first();
        $normalizedCurrency = $this->accounting->normalizeCurrency($currency);
        if ($sourceTransaction
            && (int) ($sourceTransaction->customer_id ?? 0) === (int) ($customerId ?? 0)
            && (int) ($sourceTransaction->seller_id ?? 0) === (int) ($sellerId ?? 0)
            && $this->accounting->normalizeCurrency($sourceTransaction->currency) === $normalizedCurrency) {
            $amount = (float) $sourceTransaction->amount;
            $after = (float) $sourceTransaction->balance_after;

            return $sourceTransaction->type === 'taken'
                ? $after - $amount
                : $after + $amount;
        }

        if (Schema::hasTable('accounting_journal_lines') && Schema::hasTable('accounting_accounts')) {
            $ledgerBalance = DB::table('accounting_journal_lines as lines')
                ->join('accounting_journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
                ->join('accounting_accounts as accounts', 'accounts.id', '=', 'lines.account_id')
                ->whereIn('accounts.system_key', ['accounts_receivable', 'accounts_payable'])
                ->where('entries.currency', $normalizedCurrency)
                ->when($customerId, fn ($query) => $query->where('lines.customer_id', $customerId)->whereNull('lines.seller_id'))
                ->when($sellerId, fn ($query) => $query->where('lines.seller_id', $sellerId)->whereNull('lines.customer_id'))
                ->when($accountingSourceType && $accountingSourceId, fn ($query) => $query->where(function ($nested) use ($accountingSourceType, $accountingSourceId) {
                    $nested->where('entries.source_type', '!=', $accountingSourceType)
                        ->orWhereNull('entries.source_type')
                        ->orWhere('entries.source_id', '!=', $accountingSourceId)
                        ->orWhereNull('entries.source_id');
                }))
                ->sum(DB::raw('lines.credit - lines.debit'));

            return (float) $ledgerBalance;
        }

        return (float) DebtTransaction::query()
            ->active()
            ->when($customerId, fn ($query) => $query->where('customer_id', $customerId)->whereNull('seller_id'))
            ->when($sellerId, fn ($query) => $query->where('seller_id', $sellerId)->whereNull('customer_id'))
            ->where('currency', $normalizedCurrency)
            ->where(function ($query) use ($source, $sourceId) {
                $query->whereNull('source')
                    ->orWhere('source', '!=', $source)
                    ->orWhereNull('source_id')
                    ->orWhere('source_id', '!=', $sourceId);
            })
            ->sum(DB::raw("CASE WHEN type = 'taken' THEN amount ELSE -amount END"));
    }

    private function predatesAppliedCutover(Model $model): bool
    {
        $appliedAt = $this->cutoverAppliedAt();
        if (! $appliedAt) {
            return false;
        }
        $effective = match (true) {
            $model instanceof SalesReturn => $model->completed_at ?: $model->created_at,
            $model instanceof ReturnModel => $model->settled_at ?: $model->delivered_at ?: $model->created_at,
            $model instanceof PurchasePayment => $model->created_at ?: $model->paid_at,
            $model instanceof SalesOrder => $model->financial_posted_at ?: $model->created_at,
            default => $model->created_at,
        };

        return ! $effective || Carbon::parse($effective)->lessThanOrEqualTo($appliedAt);
    }

    private function createdBeforeCutover(Model $model): bool
    {
        $appliedAt = $this->cutoverAppliedAt();

        return (bool) ($appliedAt
            && $model->created_at
            && Carbon::parse($model->created_at)->lessThanOrEqualTo($appliedAt));
    }

    private function happenedAfterCutover($value): bool
    {
        $appliedAt = $this->cutoverAppliedAt();

        return ! $appliedAt || ($value && Carbon::parse($value)->greaterThan($appliedAt));
    }

    private function cutoverAppliedAt(): ?Carbon
    {
        if (! Schema::hasTable('accounting_cutovers')) {
            return null;
        }
        $value = DB::table('accounting_cutovers')
            ->where('status', 'applied')
            ->latest('id')
            ->value('applied_at');

        return $value ? Carbon::parse($value) : null;
    }

    /** @return array<string, mixed> */
    private function line(string $accountKey, float $debit, float $credit, Model $model, array $extra = []): array
    {
        $isDeliveryCompanyLine = array_key_exists('delivery_company_id', $extra)
            && $extra['delivery_company_id'] !== null;

        return array_merge([
            'account_key' => $accountKey,
            'debit' => round($debit, 4),
            'credit' => round($credit, 4),
            'customer_id' => $isDeliveryCompanyLine ? null : ($model->getAttribute('customer_id') ?: $model->getAttribute('buyer_id')),
            'seller_id' => $isDeliveryCompanyLine ? null : $model->getAttribute('seller_id'),
            'box_id' => $model->getAttribute('box_id') ?: $model->getAttribute('payment_box_id'),
            'product_id' => $model->getAttribute('product_id'),
        ], array_filter($extra, fn ($value) => $value !== null));
    }

    /** @return array{type:string,id:int}|null */
    private function sourceIdentity(Model $model): ?array
    {
        return match (true) {
            $model instanceof InstantSale => ['type' => 'instant_sale', 'id' => (int) ($model->parent_id ?: $model->id)],
            $model instanceof MaintenancePayment => ['type' => 'maintenance_payment', 'id' => (int) $model->id],
            $model instanceof InventoryAdjustment => ['type' => 'inventory_adjustment', 'id' => (int) $model->id],
            $model instanceof ProfitSale => ['type' => 'profit_sale', 'id' => (int) $model->id],
            $model instanceof Expense => ['type' => 'expense', 'id' => (int) $model->id],
            $model instanceof EmployeeOrder => ['type' => 'employee_advance', 'id' => (int) $model->id],
            $model instanceof EmployeeAdvanceApplication => ['type' => 'employee_advance_application', 'id' => (int) $model->id],
            $model instanceof SalaryPaymentItem => ['type' => 'salary_payment', 'id' => (int) $model->id],
            $model instanceof SalesReturn => ['type' => 'sales_return', 'id' => (int) $model->id],
            $model instanceof PurchaseReceipt => ['type' => 'purchase_receipt', 'id' => (int) $model->id],
            $model instanceof PurchaseReceiptItem => ['type' => 'purchase_receipt', 'id' => (int) $model->purchase_receipt_id],
            $model instanceof PurchasePayment => ['type' => 'purchase_payment', 'id' => (int) $model->id],
            $model instanceof ReturnModel => ['type' => 'purchase_return', 'id' => (int) $model->id],
            $model instanceof Asset => ['type' => 'asset', 'id' => (int) $model->id],
            $model instanceof AssetLog => ['type' => 'asset_depreciation', 'id' => (int) $model->id],
            $model instanceof ProjectExpense => ['type' => 'project_expense', 'id' => (int) $model->id],
            $model instanceof IncomingCheck => ['type' => 'incoming_check', 'id' => (int) $model->id],
            $model instanceof OutgoingCheck => ['type' => 'outgoing_check', 'id' => (int) $model->id],
            $model instanceof BoxLog => ['type' => $model->type === 'transfer' ? 'box_transfer' : 'box_adjustment', 'id' => (int) $model->id],
            $model instanceof SalesOrderSettlement => ['type' => 'sales_order_settlement', 'id' => (int) $model->id],
            $model instanceof SalesOrder => ['type' => 'sales_order', 'id' => (int) $model->id],
            $model instanceof DebtTransaction => ['type' => 'debt_transaction', 'id' => (int) $model->id],
            default => null,
        };
    }

    private function failureKey(Model $model): string
    {
        $source = $this->sourceIdentity($model);

        return $source ? $source['type'].':'.$source['id'] : $model::class.':'.$model->getKey();
    }

    private function recordFailure(Model $model, Throwable $e): void
    {
        if (! Schema::hasTable('accounting_projection_failures')) {
            return;
        }
        $source = $this->sourceIdentity($model) ?: ['type' => class_basename($model), 'id' => (int) $model->getKey()];
        $key = $this->failureKey($model);
        $existing = DB::table('accounting_projection_failures')->where('source_key', $key)->first();
        $existingContext = $existing?->context ? json_decode($existing->context, true) : [];
        $reason = $this->failureReason($e);
        DB::table('accounting_projection_failures')->updateOrInsert(
            ['source_key' => $key],
            [
                'source_type' => $source['type'],
                'source_id' => $source['id'],
                'error' => mb_substr($e->getMessage(), 0, 4000),
                'context' => json_encode(array_merge(is_array($existingContext) ? $existingContext : [], [
                    'model' => $model::class,
                    'reason' => $reason,
                    'exception' => $e::class,
                    'message' => mb_substr($e->getMessage(), 0, 4000),
                    'failed_at' => now()->toISOString(),
                ]), JSON_UNESCAPED_UNICODE),
                'attempts' => ((int) ($existing->attempts ?? 0)) + 1,
                'last_failed_at' => now(),
                'resolved_at' => null,
                'created_at' => $existing->created_at ?? now(),
                'updated_at' => now(),
            ]
        );
    }

    private function failureReason(Throwable $exception): string
    {
        $message = mb_strtolower($exception->getMessage());

        return match (true) {
            str_contains($message, 'fifo'), str_contains($message, 'cost'), str_contains($message, 'allocation') => 'inventory_cost_integrity',
            str_contains($message, 'period'), str_contains($message, 'فترة') => 'accounting_period',
            str_contains($message, 'account'), str_contains($message, 'الحساب') => 'system_account',
            str_contains($message, 'carrier'), str_contains($message, 'delivery company') => 'missing_party_dimension',
            default => 'projection_exception',
        };
    }

    private function profitSaleRevenueAccount(ProfitSale $sale): string
    {
        $legacyAccount = DB::table('accounting_journal_entries as entries')
            ->join('accounting_journal_lines as lines', 'lines.journal_entry_id', '=', 'entries.id')
            ->join('accounting_accounts as accounts', 'accounts.id', '=', 'lines.account_id')
            ->where('entries.source_type', 'profit_sale')
            ->where('entries.source_id', $sale->id)
            ->whereNull('entries.reverses_entry_id')
            ->whereIn('accounts.system_key', ['service_revenue', 'other_revenue'])
            ->value('accounts.system_key');

        return $legacyAccount ?: 'service_revenue';
    }

    private function resolveFailure(Model $model): void
    {
        if (Schema::hasTable('accounting_projection_failures')) {
            DB::table('accounting_projection_failures')
                ->where('source_key', $this->failureKey($model))
                ->whereNull('resolved_at')
                ->update(['resolved_at' => now(), 'updated_at' => now()]);
        }
    }

    private function reverseOtherState(string $sourceType, int $sourceId, string $currentKey, $date): void
    {
        $hasOtherState = AccountingJournalEntry::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('status', AccountingJournalEntry::STATUS_POSTED)
            ->whereNull('reverses_entry_id')
            ->where('source_key', '!=', $currentKey)
            ->exists();

        if ($hasOtherState) {
            $this->accounting->reverse($sourceType, $sourceId, $date, 'عكس حالة سابقة للمصدر', auth()->id());
        }
    }
}
