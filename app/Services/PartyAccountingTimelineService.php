<?php

namespace App\Services;

use App\Models\AccountingJournalEntry;
use App\Models\DebtTransaction;
use App\Models\IncomingCheck;
use App\Models\InstantSale;
use App\Models\OutgoingCheck;
use App\Models\ProfitSale;
use App\Models\PurchasePayment;
use App\Models\PurchaseReceipt;
use App\Models\ReturnModel;
use App\Models\SalesOrder;
use App\Models\SalesOrderSettlement;
use App\Models\SalesReturn;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class PartyAccountingTimelineService
{
    /** @var array<string, array{customer_id:?int,seller_id:?int,currency:string,earliest_date:string}> */
    private array $scheduled = [];

    private bool $afterCommitRegistered = false;

    private int $guardDepth = 0;

    public function __construct(private AccountingProjectionService $projection) {}

    public function schedule(?int $customerId, ?int $sellerId, ?string $currency, mixed $earliestDate): void
    {
        if ($this->guardDepth > 0 || (! $customerId && ! $sellerId)) {
            return;
        }

        $currency = $this->normalizeCurrency($currency);
        $date = Carbon::parse($earliestDate ?: now())->toDateString();
        $key = ($customerId ? 'customer:'.$customerId : 'seller:'.$sellerId).'|'.$currency;
        $current = $this->scheduled[$key] ?? null;
        $this->scheduled[$key] = [
            'customer_id' => $customerId,
            'seller_id' => $sellerId,
            'currency' => $currency,
            'earliest_date' => ! $current || $date < $current['earliest_date'] ? $date : $current['earliest_date'],
        ];

        if ($this->afterCommitRegistered) {
            return;
        }

        $this->afterCommitRegistered = true;
        DB::afterCommit(fn () => $this->flushScheduled());
    }

    /** @return array{groups:int,sources:int,failures:int} */
    public function flushScheduled(): array
    {
        if ($this->guardDepth > 0) {
            return ['groups' => 0, 'sources' => 0, 'failures' => 0];
        }

        $impacts = array_values($this->scheduled);
        $this->scheduled = [];
        $this->afterCommitRegistered = false;
        $result = ['groups' => count($impacts), 'sources' => 0, 'failures' => 0];

        $this->guardDepth++;
        try {
            foreach ($impacts as $impact) {
                try {
                    $sources = $this->affectedSources($impact);
                } catch (Throwable $exception) {
                    $result['failures']++;
                    Log::error('party_accounting_timeline_source_discovery_failed', [
                        'impact' => $impact,
                        'message' => $exception->getMessage(),
                    ]);
                    report($exception);

                    continue;
                }

                foreach ($sources as $candidate) {
                    try {
                        $this->projection->syncOrFail($candidate['model']);
                        $result['sources']++;
                    } catch (Throwable $exception) {
                        $this->projection->recordFailureFor($candidate['model'], $exception);
                        $result['failures']++;
                        Log::error('party_accounting_timeline_reprojection_failed', [
                            'impact' => $impact,
                            'source_type' => $candidate['model']->getMorphClass(),
                            'source_id' => $candidate['model']->getKey(),
                            'message' => $exception->getMessage(),
                        ]);
                        report($exception);
                    }
                }
            }
        } finally {
            $this->guardDepth--;
        }

        if ($result['failures'] > 0) {
            Log::warning('party_accounting_timeline_reprojection_completed_with_failures', $result);
        }

        return $result;
    }

    /** @param array{customer_id:?int,seller_id:?int,currency:string,earliest_date:string} $impact */
    private function affectedSources(array $impact): Collection
    {
        $candidates = collect();

        if (Schema::hasTable('accounting_journal_entries') && Schema::hasTable('accounting_journal_lines')) {
            DB::table('accounting_journal_entries as entries')
                ->join('accounting_journal_lines as lines', 'lines.journal_entry_id', '=', 'entries.id')
                ->where('entries.status', AccountingJournalEntry::STATUS_POSTED)
                ->whereNull('entries.reverses_entry_id')
                ->where('entries.currency', $impact['currency'])
                ->whereDate('entries.entry_date', '>=', $impact['earliest_date'])
                ->whereIn('entries.source_type', array_keys($this->accountingSourceRegistry()))
                ->when(
                    $impact['customer_id'],
                    fn ($query, $id) => $query->where('lines.customer_id', $id),
                    fn ($query) => $query->where('lines.seller_id', $impact['seller_id']),
                )
                ->select(['entries.source_type', 'entries.source_id', 'entries.entry_date'])
                ->distinct()
                ->get()
                ->each(function ($row) use ($candidates) {
                    $model = $this->resolveAccountingSource((string) $row->source_type, (int) $row->source_id);
                    if ($model) {
                        $this->putCandidate($candidates, $model, (string) $row->entry_date);
                    }
                });
        }

        if (Schema::hasTable('debt_transactions')) {
            DebtTransaction::query()
                ->active()
                ->where('currency', $impact['currency'])
                ->whereDate('transaction_date', '>=', $impact['earliest_date'])
                ->when(
                    $impact['customer_id'],
                    fn ($query, $id) => $query->where('customer_id', $id)->whereNull('seller_id'),
                    fn ($query) => $query->where('seller_id', $impact['seller_id'])->whereNull('customer_id'),
                )
                ->orderBy('transaction_date')
                ->orderBy('id')
                ->get()
                ->each(function (DebtTransaction $transaction) use ($candidates) {
                    foreach ($this->resolveDebtSource($transaction) as $model) {
                        $this->putCandidate($candidates, $model, $transaction->transaction_date?->toDateString());
                    }
                });
        }

        return $candidates->values()
            ->sortBy(fn (array $candidate) => sprintf(
                '%s|%03d|%s|%020d',
                $candidate['effective_date'],
                $this->sourcePriority($candidate['model']),
                $candidate['model']->getMorphClass(),
                (int) $candidate['model']->getKey(),
            ))
            ->values();
    }

    private function putCandidate(Collection $candidates, Model $model, ?string $effectiveDate): void
    {
        $key = $model::class.':'.$model->getKey();
        $date = Carbon::parse($effectiveDate ?: $model->created_at ?: now())->toDateString();
        $existing = $candidates->get($key);
        if (! $existing || $date < $existing['effective_date']) {
            $candidates->put($key, ['model' => $model, 'effective_date' => $date]);
        }
    }

    /** @return array<string, class-string<Model>> */
    public function accountingSourceRegistry(): array
    {
        return [
            'instant_sale' => InstantSale::class,
            'profit_sale' => ProfitSale::class,
            'sales_return' => SalesReturn::class,
            'purchase_receipt' => PurchaseReceipt::class,
            'purchase_payment' => PurchasePayment::class,
            'purchase_return' => ReturnModel::class,
            'incoming_check' => IncomingCheck::class,
            'outgoing_check' => OutgoingCheck::class,
            'sales_order_settlement' => SalesOrderSettlement::class,
            'sales_order' => SalesOrder::class,
            'debt_transaction' => DebtTransaction::class,
        ];
    }

    private function resolveAccountingSource(string $sourceType, int $sourceId): ?Model
    {
        $class = $this->accountingSourceRegistry()[$sourceType] ?? null;

        return $class ? $class::query()->find($sourceId) : null;
    }

    /** @return Collection<int, Model> */
    private function resolveDebtSource(DebtTransaction $transaction): Collection
    {
        $source = trim((string) $transaction->source);
        $sourceId = (int) $transaction->source_id;
        if ($source === '' || $source === 'manual') {
            return collect([$transaction]);
        }

        $models = match ($source) {
            'instant_sale' => collect([InstantSale::query()->find($sourceId)]),
            'maintenance' => InstantSale::query()->where('maintenance_id', $sourceId)->whereNull('parent_id')->get(),
            'profit_sale' => collect([ProfitSale::query()->find($sourceId)]),
            'sales_order' => collect([
                InstantSale::query()->where('sales_order_id', $sourceId)->whereNull('parent_id')->latest('id')->first()
                    ?: SalesOrder::query()->find($sourceId),
            ]),
            'purchase_invoice' => PurchaseReceipt::query()->where('bill_id', $sourceId)->get(),
            'purchase_payment', 'purchase_initial_payment', 'purchase_account_payment' => collect([
                PurchasePayment::query()->where('debt_transaction_id', $transaction->id)->first()
                    ?: PurchasePayment::query()->whereKey($sourceId)->first(),
            ]),
            'purchase_return', 'purchase_refund', 'purchase_settlement' => collect([
                ReturnModel::query()->where('debt_transaction_id', $transaction->id)->first()
                    ?: ReturnModel::query()->whereKey($sourceId)->first(),
            ]),
            'sales_return' => collect([
                SalesReturn::query()->where('debt_transaction_id', $transaction->id)->first()
                    ?: SalesReturn::query()->whereKey($sourceId)->first(),
            ]),
            'incoming_check', 'incoming_check_disposal' => collect([IncomingCheck::query()->find($sourceId)]),
            'outgoing_check' => collect([OutgoingCheck::query()->find($sourceId)]),
            default => collect(),
        };

        return $models->filter(fn ($model) => $model instanceof Model)->values();
    }

    private function sourcePriority(Model $model): int
    {
        $priority = array_search($model::class, array_values($this->accountingSourceRegistry()), true);

        return $priority === false ? 999 : $priority;
    }

    private function normalizeCurrency(?string $currency): string
    {
        $currency = trim((string) $currency);

        return in_array($currency, DebtLedgerService::CURRENCIES, true) ? $currency : 'شيكل';
    }
}
