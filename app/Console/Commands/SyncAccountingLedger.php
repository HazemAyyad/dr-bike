<?php

namespace App\Console\Commands;

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
use App\Services\AccountingProjectionService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SyncAccountingLedger extends Command
{
    protected $signature = 'accounting:sync
        {--from= : Include sources created on or after this date}
        {--to= : Include sources created on or before this date}
        {--source= : Sync one source name only}
        {--chunk=200 : Chunk size}
        {--dry-run : Count candidates without writing journals}';

    protected $description = 'Idempotently project operational records into the double-entry accounting ledger';

    public function handle(AccountingProjectionService $projection): int
    {
        if (! Schema::hasTable('accounting_journal_entries')) {
            $this->error('Accounting migrations are not applied.');

            return self::FAILURE;
        }

        $sources = $this->sources();
        if (Schema::hasTable('accounting_cutovers')) {
            $cutover = DB::table('accounting_cutovers')->where('status', 'applied')->latest('id')->first();
            if ($cutover) {
                $this->info('Applied cutover: '.$cutover->cutover_date.'. Pre-cutover source state is ignored; later lifecycle events are projected as deltas.');
            }
        }
        $requested = trim((string) $this->option('source'));
        if ($requested !== '') {
            if (! isset($sources[$requested])) {
                $this->error('Unknown source. Available: '.implode(', ', array_keys($sources)));

                return self::FAILURE;
            }
            $sources = [$requested => $sources[$requested]];
        }

        $chunk = min(max((int) $this->option('chunk'), 25), 1000);
        $dryRun = (bool) $this->option('dry-run');
        $total = 0;
        foreach ($sources as $name => [$model, $scope]) {
            /** @var Builder $query */
            $query = $model::query();
            $scope($query);
            $this->applyDates($query);
            $count = (clone $query)->count();
            $this->line($name.': '.$count.' source rows'.($dryRun ? ' (dry run)' : ''));
            $total += $count;
            if ($dryRun) {
                continue;
            }

            $query->orderBy('id')->chunkById($chunk, function ($rows) use ($projection) {
                foreach ($rows as $row) {
                    $projection->sync($row);
                }
            });
        }

        $failures = DB::table('accounting_projection_failures')->whereNull('resolved_at')->count();
        $this->info('Processed '.$total.' source rows. Open projection failures: '.$failures.'.');

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<string, array{0:class-string,1:callable(Builder):void}> */
    private function sources(): array
    {
        return [
            'instant_sales' => [InstantSale::class, fn (Builder $query) => $query->whereNull('parent_id')],
            'inventory_adjustments' => [InventoryAdjustment::class, fn (Builder $query) => null],
            'profit_sales' => [ProfitSale::class, fn (Builder $query) => null],
            'expenses' => [Expense::class, fn (Builder $query) => null],
            'employee_advances' => [EmployeeOrder::class, fn (Builder $query) => $query->where('type', 'loan')],
            'employee_advance_applications' => [EmployeeAdvanceApplication::class, fn (Builder $query) => null],
            'salary_payments' => [SalaryPaymentItem::class, fn (Builder $query) => null],
            'sales_returns' => [SalesReturn::class, fn (Builder $query) => null],
            'purchase_receipts' => [PurchaseReceipt::class, fn (Builder $query) => null],
            'purchase_payments' => [PurchasePayment::class, fn (Builder $query) => null],
            'purchase_returns' => [ReturnModel::class, fn (Builder $query) => null],
            'assets' => [Asset::class, fn (Builder $query) => null],
            'asset_depreciation' => [AssetLog::class, fn (Builder $query) => $query->where('type', 'depreciate')],
            'project_expenses' => [ProjectExpense::class, fn (Builder $query) => null],
            'incoming_checks' => [IncomingCheck::class, fn (Builder $query) => null],
            'outgoing_checks' => [OutgoingCheck::class, fn (Builder $query) => null],
            'box_logs' => [BoxLog::class, fn (Builder $query) => $query->where(function (Builder $nested) {
                $nested->where('type', 'transfer')
                    ->orWhereIn('description', ['تم اضافة رصيد للصندوق', 'تم سحب رصيد من الصندوق']);
            })],
            'sales_order_settlements' => [SalesOrderSettlement::class, fn (Builder $query) => null],
            'sales_orders' => [SalesOrder::class, fn (Builder $query) => $query->whereNotNull('financial_posted_at')],
            'manual_debt_cash' => [DebtTransaction::class, fn (Builder $query) => $query->whereNotNull('box_id')->where(function ($nested) {
                $nested->whereNull('source')->orWhereIn('source', ['manual', '']);
            })],
        ];
    }

    private function applyDates(Builder $query): void
    {
        if ($this->option('from')) {
            $query->whereDate('created_at', '>=', $this->option('from'));
        }
        if ($this->option('to')) {
            $query->whereDate('created_at', '<=', $this->option('to'));
        }
    }
}
