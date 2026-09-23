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
use App\Models\OutgoingCheck;
use App\Models\ProfitSale;
use App\Models\ProjectExpense;
use App\Models\PurchasePayment;
use App\Models\PurchaseReceipt;
use App\Models\ReturnModel;
use App\Models\SalaryPaymentItem;
use App\Models\SalesOrder;
use App\Models\SalesOrderSettlement;
use App\Models\SalesReturn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class AccountingProjectionRepairService
{
    public function __construct(
        private AccountingProjectionService $projection,
        private InventoryCostIntegrityService $inventoryIntegrity,
    ) {}

    /** @return array{summary:array<string,int>,items:array<int,array<string,mixed>>} */
    public function run(bool $dryRun = false): array
    {
        $summary = [
            'total_failures' => 0,
            'repairable' => 0,
            'successfully_repaired' => 0,
            'still_failing' => 0,
            'already_valid_or_stale' => 0,
            'skipped' => 0,
        ];
        $items = [];

        if (! Schema::hasTable('accounting_projection_failures')) {
            return compact('summary', 'items');
        }

        DB::table('accounting_projection_failures')
            ->whereNull('resolved_at')
            ->orderBy('id')
            ->get()
            ->each(function (object $failure) use ($dryRun, &$summary, &$items) {
                $summary['total_failures']++;
                $result = $this->repairFailure($failure, $dryRun);
                if ($result['summary_key'] !== null) {
                    $summary[$result['summary_key']]++;
                }
                if ($result['repairable']) {
                    $summary['repairable']++;
                }
                unset($result['summary_key']);
                $items[] = $result;
            });

        return compact('summary', 'items');
    }

    /** @return array<string, mixed> */
    private function repairFailure(object $failure, bool $dryRun): array
    {
        $base = [
            'failure_id' => (int) $failure->id,
            'source_type' => (string) $failure->source_type,
            'source_id' => (int) $failure->source_id,
            'previous_error' => (string) $failure->error,
            'repairable' => false,
        ];
        $modelClass = $this->sourceModel((string) $failure->source_type);
        if (! $modelClass) {
            return array_merge($base, [
                'status' => 'skipped',
                'message' => 'Unsupported projection source type.',
                'summary_key' => 'skipped',
            ]);
        }

        $model = $this->findSource($modelClass, (int) $failure->source_id);
        if (! $model) {
            if (! $dryRun) {
                $this->resolve($failure, 'stale_source_missing', ['model' => $modelClass]);
            }

            return array_merge($base, [
                'status' => 'already_valid_or_stale',
                'message' => 'Source no longer exists; no journal can be recreated from it.',
                'summary_key' => 'already_valid_or_stale',
            ]);
        }

        if ($this->hasValidJournal($failure, $model)) {
            if (! $dryRun) {
                $this->resolve($failure, 'journal_already_valid');
            }

            return array_merge($base, [
                'status' => 'already_valid_or_stale',
                'message' => 'A balanced, structurally valid journal already exists.',
                'summary_key' => 'already_valid_or_stale',
            ]);
        }

        if (! $this->expectsActiveJournal($model)) {
            if (! $dryRun) {
                $this->resolve($failure, 'stale_source_inactive');
            }

            return array_merge($base, [
                'status' => 'already_valid_or_stale',
                'message' => 'Source is inactive or cancelled and no active journal is required.',
                'summary_key' => 'already_valid_or_stale',
            ]);
        }

        $preflight = $this->preflight($model);
        if (! $preflight['repairable']) {
            if (! $dryRun) {
                $this->recordRepairAttempt(
                    $failure,
                    new RuntimeException($preflight['message']),
                    ['issues' => $preflight['issues']],
                );
            }

            return array_merge($base, [
                'status' => 'still_failing',
                'message' => $preflight['message'],
                'issues' => $preflight['issues'],
                'summary_key' => 'still_failing',
            ]);
        }

        if ($dryRun) {
            return array_merge($base, [
                'status' => 'repairable',
                'repairable' => true,
                'message' => $preflight['message'].' Read-only preflight passed; no projection was written.',
                'issues' => $preflight['issues'],
                'summary_key' => null,
            ]);
        }

        $repairDetails = [];
        try {
            $entry = DB::transaction(function () use (&$model, $preflight, $failure, &$repairDetails) {
                if ($model instanceof InstantSale && ($preflight['inventory']['repairable'] ?? false)) {
                    $before = $preflight['inventory'];
                    $after = $this->inventoryIntegrity->repairInstantSaleSnapshots($model);
                    if (! $after['ready']) {
                        throw new RuntimeException('FIFO snapshot repair did not produce a projection-ready sale.');
                    }
                    $repairDetails = [
                        'snapshot_repairs' => $before['repairs'],
                        'inventory_total_cost' => $after['total_cost'],
                    ];
                    $model = $model->fresh();
                }

                $entry = $this->projection->syncOrFail($model);
                if ($this->expectsActiveJournal($model) && ! $entry && ! $this->hasValidJournal($failure, $model)) {
                    throw new RuntimeException('Projection returned without creating or locating the required journal entry.');
                }
                if ($this->expectsActiveJournal($model) && ! $this->hasValidJournal($failure, $model)) {
                    throw new RuntimeException('Projection completed but the resulting journal failed structural verification.');
                }

                $this->resolve($failure, 'repaired', $repairDetails);

                return $entry;
            }, 3);

            return array_merge($base, [
                'status' => 'successfully_repaired',
                'repairable' => true,
                'message' => 'Projection repaired and verified without duplicate journals.',
                'journal_entry_id' => $entry?->id,
                'summary_key' => 'successfully_repaired',
            ]);
        } catch (Throwable $exception) {
            $this->projection->recordFailureFor($model, $exception);
            $this->recordRepairAttempt($failure, $exception, $repairDetails);

            return array_merge($base, [
                'status' => 'still_failing',
                'repairable' => true,
                'message' => $exception->getMessage(),
                'summary_key' => 'still_failing',
            ]);
        }
    }

    /** @return array{repairable:bool,message:string,issues:array<int,mixed>,inventory?:array<string,mixed>} */
    private function preflight(Model $model): array
    {
        if ($model instanceof InstantSale) {
            $inventory = $this->inventoryIntegrity->inspectInstantSale($model);
            if ((float) $model->total_cost <= 0 && $inventory['requires_cost']) {
                $inventory['issues'][] = [
                    'code' => 'zero_revenue_with_fifo_cost',
                    'message' => 'Product sale has zero revenue but a FIFO inventory cost; accounting treatment requires review.',
                    'context' => ['sale_id' => (int) $model->id],
                ];

                return [
                    'repairable' => false,
                    'message' => 'Product sale has zero revenue and a real FIFO cost; no journal will be guessed.',
                    'issues' => $inventory['issues'],
                    'inventory' => $inventory,
                ];
            }

            return [
                'repairable' => $inventory['ready'] || $inventory['repairable'],
                'message' => $inventory['ready']
                    ? 'FIFO evidence and snapshots are complete.'
                    : ($inventory['repairable']
                        ? 'FIFO evidence is complete and missing snapshots can be rebuilt without guessing.'
                        : 'FIFO evidence is incomplete; the projection must remain open.'),
                'issues' => $inventory['issues'],
                'inventory' => $inventory,
            ];
        }

        if ($model instanceof SalesOrder) {
            $inventory = $this->inventoryIntegrity->inspectSalesOrder($model);

            return [
                'repairable' => $inventory['ready'],
                'message' => $inventory['ready'] ? 'Sales-order FIFO evidence is complete.' : 'Sales-order FIFO evidence is incomplete.',
                'issues' => $inventory['issues'],
                'inventory' => $inventory,
            ];
        }

        if ($model instanceof ReturnModel) {
            $inventory = $this->inventoryIntegrity->inspectPurchaseReturn($model);
            $settlementAmount = round(max(0, (float) $model->total), 4);
            $fifoAmount = round((float) $inventory['total_cost'], 4);
            $matchesSettlement = abs($settlementAmount - $fifoAmount) <= 0.0001;
            $issues = $inventory['issues'];
            if (! $matchesSettlement) {
                $issues[] = [
                    'code' => 'purchase_return_value_mismatch',
                    'message' => 'Purchase-return settlement does not match verified FIFO cost.',
                    'context' => [
                        'return_id' => (int) $model->id,
                        'settlement_total' => $settlementAmount,
                        'fifo_total' => $fifoAmount,
                    ],
                ];
            }

            return [
                'repairable' => $inventory['ready'] && $matchesSettlement,
                'message' => $inventory['ready'] && $matchesSettlement
                    ? 'Purchase-return FIFO evidence is complete.'
                    : 'Purchase-return FIFO evidence or settlement value requires review.',
                'issues' => $issues,
                'inventory' => $inventory,
            ];
        }

        if ($model instanceof SalesReturn) {
            $model->loadMissing('items');
            $missing = $model->items
                ->filter(fn ($item) => (float) $item->quantity > 0 && $item->inventory_total_cost === null)
                ->pluck('id')->map(fn ($id) => (int) $id)->all();

            return [
                'repairable' => $missing === [],
                'message' => $missing === []
                    ? 'Sales-return cost snapshots are complete.'
                    : 'Sales-return items are missing their original FIFO cost snapshots.',
                'issues' => $missing === [] ? [] : [[
                    'code' => 'missing_sales_return_cost',
                    'message' => 'Original FIFO cost is required for every returned product.',
                    'context' => ['item_ids' => $missing],
                ]],
            ];
        }

        return [
            'repairable' => true,
            'message' => 'Source exists and has no known unresolved FIFO blocker.',
            'issues' => [],
        ];
    }

    private function hasValidJournal(object $failure, Model $model): bool
    {
        $sourceType = (string) $failure->source_type;
        $sourceId = (int) $failure->source_id;
        if ($model instanceof InstantSale) {
            $sourceType = 'instant_sale';
            $sourceId = (int) ($model->parent_id ?: $model->id);
        } elseif ($model instanceof SalesOrder) {
            $linkedSaleId = InstantSale::query()
                ->where('sales_order_id', $model->id)
                ->whereNull('parent_id')
                ->latest('id')
                ->value('id');
            if ($linkedSaleId) {
                $sourceType = 'instant_sale';
                $sourceId = (int) $linkedSaleId;
            }
        }

        $entries = AccountingJournalEntry::query()
            ->with('lines.account')
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('status', AccountingJournalEntry::STATUS_POSTED)
            ->whereNull('reverses_entry_id')
            ->get();

        $allowsLifecycleEntries = $model instanceof IncomingCheck || $model instanceof OutgoingCheck;
        if ($entries->isEmpty() || (! $allowsLifecycleEntries && $entries->count() !== 1)) {
            return false;
        }

        foreach ($entries as $entry) {
            $debit = (float) $entry->lines->sum('debit');
            $credit = (float) $entry->lines->sum('credit');
            if ($entry->lines->count() < 2 || $debit <= 0.0001 || $credit <= 0.0001 || abs($debit - $credit) > 0.0001) {
                return false;
            }
        }

        $accounts = $entries->flatMap->lines->pluck('account.system_key')->filter()->unique();
        if ($model instanceof InstantSale) {
            $requiredRevenue = $model->maintenance_id ? 'maintenance_revenue' : 'sales_revenue';
            if (! $accounts->contains($requiredRevenue)) {
                return false;
            }
            $cost = $this->inventoryIntegrity->inspectInstantSale($model);
            if ($cost['requires_cost']) {
                return $accounts->contains('cost_of_goods_sold') && $accounts->contains('inventory');
            }
        }
        if ($model instanceof ProfitSale) {
            return ($accounts->contains('service_revenue') || $accounts->contains('other_revenue'))
                && ! $accounts->contains('inventory')
                && ! $accounts->contains('cost_of_goods_sold');
        }
        if ($model instanceof SalesOrder) {
            $cost = $this->inventoryIntegrity->inspectSalesOrder($model);

            return $accounts->contains('sales_revenue')
                && (! $cost['requires_cost'] || ($accounts->contains('cost_of_goods_sold') && $accounts->contains('inventory')));
        }
        if ($model instanceof PurchaseReceipt) {
            return $accounts->contains('inventory')
                && $accounts->intersect(['accounts_payable', 'accounts_receivable'])->isNotEmpty();
        }
        if ($model instanceof PurchasePayment) {
            return $accounts->intersect(['cash', 'clearing'])->isNotEmpty()
                && $accounts->intersect(['accounts_payable', 'accounts_receivable'])->isNotEmpty();
        }
        if ($model instanceof ReturnModel) {
            return $accounts->contains('inventory')
                && $accounts->intersect(['cash', 'accounts_payable', 'accounts_receivable', 'clearing'])->isNotEmpty();
        }
        if ($model instanceof SalesReturn) {
            $model->loadMissing('items');
            $requiresCost = $model->items->contains(fn ($item) => (float) $item->quantity > 0);

            return $accounts->contains('sales_returns')
                && (! $requiresCost || ($accounts->contains('inventory') && $accounts->contains('cost_of_goods_sold')));
        }
        if ($model instanceof Expense) {
            $expenseAccount = match ($model->expense_type) {
                'salary' => 'salary_expense',
                'destruction' => 'inventory_loss',
                default => $model->payment_method === 'carrier_withholding' ? 'delivery_expense' : 'general_expense',
            };
            $counterpart = $model->expense_type === 'salary'
                ? 'salary_payable'
                : ($model->box_id ? 'cash' : 'clearing');

            return $accounts->contains($expenseAccount) && $accounts->contains($counterpart);
        }
        if ($model instanceof Asset) {
            return $accounts->contains('fixed_assets')
                && $accounts->contains($model->box_id ? 'cash' : 'clearing');
        }
        if ($model instanceof AssetLog) {
            return $accounts->contains('depreciation_expense')
                && $accounts->contains('accumulated_depreciation');
        }
        if ($model instanceof ProjectExpense) {
            return $accounts->contains('project_expense')
                && $accounts->contains($model->box_id ? 'cash' : 'clearing');
        }
        if ($model instanceof BoxLog) {
            return $accounts->contains('cash')
                && ($model->type === 'transfer' || $accounts->contains('owner_equity'));
        }
        if ($model instanceof DebtTransaction) {
            return $accounts->contains('cash')
                && $accounts->intersect(['accounts_payable', 'accounts_receivable'])->isNotEmpty();
        }
        if ($model instanceof IncomingCheck) {
            return $accounts->contains('checks_receivable')
                && $accounts->intersect(['accounts_payable', 'accounts_receivable'])->isNotEmpty();
        }
        if ($model instanceof OutgoingCheck) {
            return $accounts->contains('checks_payable')
                && $accounts->intersect(['accounts_payable', 'accounts_receivable'])->isNotEmpty();
        }

        return true;
    }

    private function expectsActiveJournal(Model $model): bool
    {
        if ($model instanceof InstantSale) {
            $inventory = $this->inventoryIntegrity->inspectInstantSale($model);

            return ! $model->isCancelled()
                && ((float) $model->total_cost > 0 || $inventory['requires_cost']);
        }

        return match (true) {
            $model instanceof ProfitSale => ! $model->isCancelled() && (float) $model->total_cost > 0,
            $model instanceof SalesOrder => ! $model->is_debt_collection && $model->status !== 'canceled' && $model->financial_posted_at !== null,
            $model instanceof SalesReturn => in_array($model->return_type, ['direct', 'partial'], true) && $model->status === 'completed' && ! $model->cancelled_at,
            $model instanceof ReturnModel => in_array($model->status, ['delivered', 'settled'], true) && ! $model->cancelled_at,
            default => true,
        };
    }

    private function resolve(object $failure, string $reason, array $details = []): void
    {
        // syncOrFail resolves the row itself after a successful projection. Read
        // the newest context and update by id so the repair audit trail is not
        // lost merely because resolved_at was populated milliseconds earlier.
        $current = DB::table('accounting_projection_failures')->where('id', $failure->id)->first();
        $rawContext = $current?->context ?? $failure->context;
        $context = $rawContext ? json_decode($rawContext, true) : [];
        if (! is_array($context)) {
            $context = [];
        }
        $context['repair'] = array_merge([
            'status' => $reason,
            'resolved_at' => now()->toISOString(),
        ], $details);

        DB::table('accounting_projection_failures')
            ->where('id', $failure->id)
            ->update([
                'context' => json_encode($context, JSON_UNESCAPED_UNICODE),
                'resolved_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function recordRepairAttempt(object $failure, Throwable $exception, array $details = []): void
    {
        $current = DB::table('accounting_projection_failures')->where('id', $failure->id)->first();
        if (! $current) {
            return;
        }
        $context = $current->context ? json_decode($current->context, true) : [];
        if (! is_array($context)) {
            $context = [];
        }
        $context['repair_attempt'] = array_merge([
            'status' => 'still_failing',
            'attempted_at' => now()->toISOString(),
            'message' => mb_substr($exception->getMessage(), 0, 4000),
        ], $details);

        DB::table('accounting_projection_failures')
            ->where('id', $failure->id)
            ->update([
                'context' => json_encode($context, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
    }

    /** @return class-string<Model>|null */
    private function sourceModel(string $sourceType): ?string
    {
        return match ($sourceType) {
            'instant_sale' => InstantSale::class,
            'inventory_adjustment' => InventoryAdjustment::class,
            'profit_sale' => ProfitSale::class,
            'expense' => Expense::class,
            'employee_advance' => EmployeeOrder::class,
            'employee_advance_application' => EmployeeAdvanceApplication::class,
            'salary_payment' => SalaryPaymentItem::class,
            'sales_return' => SalesReturn::class,
            'purchase_receipt' => PurchaseReceipt::class,
            'purchase_payment' => PurchasePayment::class,
            'purchase_return' => ReturnModel::class,
            'asset' => Asset::class,
            'asset_depreciation' => AssetLog::class,
            'project_expense' => ProjectExpense::class,
            'incoming_check' => IncomingCheck::class,
            'outgoing_check' => OutgoingCheck::class,
            'box_transfer', 'box_adjustment' => BoxLog::class,
            'sales_order' => SalesOrder::class,
            'sales_order_settlement' => SalesOrderSettlement::class,
            'debt_transaction' => DebtTransaction::class,
            default => null,
        };
    }

    /** @param class-string<Model> $modelClass */
    private function findSource(string $modelClass, int $id): ?Model
    {
        $query = $modelClass::query();
        if (in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
            $query->withTrashed();
        }

        return $query->find($id);
    }
}
