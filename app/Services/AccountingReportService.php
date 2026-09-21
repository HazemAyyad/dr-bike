<?php

namespace App\Services;

use App\Models\AccountingAccount;
use App\Models\AccountingJournalEntry;
use App\Models\Customer;
use App\Models\DeliveryCompany;
use App\Models\Seller;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AccountingReportService
{
    public function trialBalance(Carbon $from, Carbon $to, ?string $currency = null): array
    {
        $opening = $this->totalsQuery($from->copy()->subDay(), $currency)
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name_ar', 'accounts.type', 'accounts.normal_balance')
            ->get()
            ->keyBy('id');
        $movements = $this->totalsQuery($to, $currency)
            ->whereDate('entries.entry_date', '>=', $from->toDateString())
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name_ar', 'accounts.type', 'accounts.normal_balance')
            ->get()
            ->keyBy('id');
        $accounts = AccountingAccount::query()->where('is_active', true)->orderBy('code')->get();
        $rows = $accounts->map(function (AccountingAccount $account) use ($opening, $movements) {
            $openingRow = $opening->get($account->id);
            $movementRow = $movements->get($account->id);
            $openingBalance = round((float) ($openingRow->debit ?? 0) - (float) ($openingRow->credit ?? 0), 4);
            $movementDebit = round((float) ($movementRow->debit ?? 0), 4);
            $movementCredit = round((float) ($movementRow->credit ?? 0), 4);
            $closingBalance = round($openingBalance + $movementDebit - $movementCredit, 4);

            return [
                'account_id' => (int) $account->id,
                'code' => $account->code,
                'account' => $account->name_ar,
                'type' => $account->type,
                'opening_debit' => max($openingBalance, 0),
                'opening_credit' => abs(min($openingBalance, 0)),
                'movement_debit' => $movementDebit,
                'movement_credit' => $movementCredit,
                'closing_debit' => max($closingBalance, 0),
                'closing_credit' => abs(min($closingBalance, 0)),
                // Backwards-compatible aliases represent the closing trial balance.
                'debit' => max($closingBalance, 0),
                'credit' => abs(min($closingBalance, 0)),
                'balance' => $closingBalance,
            ];
        })->filter(fn (array $row) => abs($row['opening_debit']) > 0.0001
            || abs($row['opening_credit']) > 0.0001
            || abs($row['movement_debit']) > 0.0001
            || abs($row['movement_credit']) > 0.0001)
            ->values();

        $closingDebit = (float) $rows->sum('closing_debit');
        $closingCredit = (float) $rows->sum('closing_credit');

        return [
            'title' => 'ميزان المراجعة',
            'period' => $this->period($from, $to),
            'currency' => $currency,
            'summary' => [
                'opening_debit' => round((float) $rows->sum('opening_debit'), 4),
                'opening_credit' => round((float) $rows->sum('opening_credit'), 4),
                'movement_debit' => round((float) $rows->sum('movement_debit'), 4),
                'movement_credit' => round((float) $rows->sum('movement_credit'), 4),
                'debit' => round($closingDebit, 4),
                'credit' => round($closingCredit, 4),
                'difference' => round($closingDebit - $closingCredit, 4),
                'balanced' => abs($closingDebit - $closingCredit) <= 0.0001,
            ],
            'rows' => $rows->values(),
            'quality' => $this->quality(),
        ];
    }

    public function generalLedger(int $accountId, Carbon $from, Carbon $to, ?string $currency = null): array
    {
        $account = AccountingAccount::query()->findOrFail($accountId);
        $openingQuery = $this->lineQuery($currency)
            ->where('lines.account_id', $accountId)
            ->whereDate('entries.entry_date', '<', $from->toDateString());
        $opening = round((float) $openingQuery->sum(DB::raw('lines.debit - lines.credit')), 4);

        $running = $opening;
        $rows = $this->lineQuery($currency)
            ->where('lines.account_id', $accountId)
            ->whereBetween('entries.entry_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('entries.entry_date')
            ->orderBy('entries.id')
            ->orderBy('lines.id')
            ->get([
                'entries.id as entry_id', 'entries.entry_number', 'entries.entry_date', 'entries.currency',
                'entries.source_type', 'entries.source_id', 'entries.description as entry_description',
                'lines.debit', 'lines.credit', 'lines.description', 'lines.customer_id', 'lines.seller_id', 'lines.delivery_company_id', 'lines.box_id',
            ])
            ->map(function ($row) use (&$running) {
                $running += (float) $row->debit - (float) $row->credit;

                return [
                    'entry_id' => (int) $row->entry_id,
                    'entry_number' => $row->entry_number,
                    'date' => $row->entry_date,
                    'currency' => $row->currency,
                    'source_type' => $row->source_type,
                    'source_id' => $row->source_id,
                    'description' => $row->description ?: $row->entry_description,
                    'debit' => round((float) $row->debit, 4),
                    'credit' => round((float) $row->credit, 4),
                    'balance' => round($running, 4),
                    'customer_id' => $row->customer_id,
                    'seller_id' => $row->seller_id,
                    'delivery_company_id' => $row->delivery_company_id,
                    'box_id' => $row->box_id,
                ];
            });

        return [
            'title' => 'دفتر الأستاذ - '.$account->name_ar,
            'account' => $account,
            'period' => $this->period($from, $to),
            'currency' => $currency,
            'summary' => [
                'opening_balance' => $opening,
                'debit' => round((float) $rows->sum('debit'), 4),
                'credit' => round((float) $rows->sum('credit'), 4),
                'closing_balance' => round($running, 4),
            ],
            'rows' => $rows,
            'quality' => $this->quality(),
        ];
    }

    public function incomeStatement(Carbon $from, Carbon $to, ?string $currency = null): array
    {
        $rows = $this->accountBalances($from, $to, $currency)
            ->filter(fn ($row) => in_array($row['type'], ['revenue', 'contra_revenue', 'expense'], true));
        $revenue = $rows->where('type', 'revenue')->sum(fn ($row) => -$row['balance']);
        $returns = $rows->where('type', 'contra_revenue')->sum('balance');
        $expenses = $rows->where('type', 'expense')->sum('balance');
        $netRevenue = $revenue - $returns;

        return [
            'title' => 'قائمة الدخل',
            'period' => $this->period($from, $to),
            'currency' => $currency,
            'summary' => [
                'gross_revenue' => round((float) $revenue, 4),
                'sales_returns' => round((float) $returns, 4),
                'net_revenue' => round((float) $netRevenue, 4),
                'expenses' => round((float) $expenses, 4),
                'net_profit' => round((float) $netRevenue - (float) $expenses, 4),
            ],
            'rows' => $rows->values(),
            'quality' => $this->quality(),
        ];
    }

    public function balanceSheet(Carbon $asOf, ?string $currency = null): array
    {
        $rows = $this->accountBalances(null, $asOf, $currency);
        $assets = $rows->filter(fn ($row) => in_array($row['type'], ['asset', 'contra_asset'], true));
        $liabilities = $rows->where('type', 'liability');
        $equity = $rows->where('type', 'equity');
        $profitRows = $rows->filter(fn ($row) => in_array($row['type'], ['revenue', 'contra_revenue', 'expense'], true));
        $retainedEarnings = -$profitRows->sum('balance');
        $assetTotal = $assets->sum('balance');
        $liabilityTotal = -$liabilities->sum('balance');
        $equityTotal = -$equity->sum('balance') + $retainedEarnings;
        $liabilitySection = $liabilities->map(fn (array $row) => array_merge($row, [
            'balance' => round(-(float) $row['balance'], 4),
        ]));
        $equitySection = $equity->map(fn (array $row) => array_merge($row, [
            'balance' => round(-(float) $row['balance'], 4),
        ]));

        return [
            'title' => 'الميزانية العمومية',
            'as_of' => $asOf->toDateString(),
            'currency' => $currency,
            'summary' => [
                'assets' => round((float) $assetTotal, 4),
                'liabilities' => round((float) $liabilityTotal, 4),
                'equity' => round((float) $equityTotal, 4),
                'difference' => round((float) $assetTotal - (float) $liabilityTotal - (float) $equityTotal, 4),
                'balanced' => abs((float) $assetTotal - (float) $liabilityTotal - (float) $equityTotal) <= 0.0001,
            ],
            'sections' => [
                'assets' => $assets->values(),
                'liabilities' => $liabilitySection->values(),
                'equity' => $equitySection->values(),
                'retained_earnings' => round((float) $retainedEarnings, 4),
            ],
            'quality' => $this->quality(),
        ];
    }

    public function cashFlow(Carbon $from, Carbon $to, ?string $currency = null): array
    {
        $cashId = AccountingAccount::query()->where('system_key', 'cash')->value('id');
        $opening = (float) $this->lineQuery($currency)
            ->where('lines.account_id', $cashId)
            ->whereDate('entries.entry_date', '<', $from->toDateString())
            ->sum(DB::raw('lines.debit - lines.credit'));
        $rows = $this->lineQuery($currency)
            ->where('lines.account_id', $cashId)
            ->whereBetween('entries.entry_date', [$from->toDateString(), $to->toDateString()])
            ->where(function ($query) {
                $query->whereNull('entries.source_type')->orWhere('entries.source_type', '!=', 'box_transfer');
            })
            ->select([
                'entries.source_type', 'entries.currency',
                DB::raw('SUM(lines.debit) as cash_in'),
                DB::raw('SUM(lines.credit) as cash_out'),
            ])
            ->groupBy('entries.source_type', 'entries.currency')
            ->get()
            ->map(function ($row) {
                $section = match ($row->source_type) {
                    'asset' => 'investing',
                    'accounting_cutover', 'box_adjustment', 'opening_balance', 'equity' => 'financing',
                    default => 'operating',
                };

                return [
                    'section' => $section,
                    'source_type' => $row->source_type,
                    'currency' => $row->currency,
                    'cash_in' => round((float) $row->cash_in, 4),
                    'cash_out' => round((float) $row->cash_out, 4),
                    'net' => round((float) $row->cash_in - (float) $row->cash_out, 4),
                ];
            });
        $net = (float) $rows->sum('net');

        return [
            'title' => 'قائمة التدفقات النقدية',
            'period' => $this->period($from, $to),
            'currency' => $currency,
            'summary' => [
                'opening_cash' => round($opening, 4),
                'cash_in' => round((float) $rows->sum('cash_in'), 4),
                'cash_out' => round((float) $rows->sum('cash_out'), 4),
                'net_cash_flow' => round($net, 4),
                'closing_cash' => round($opening + $net, 4),
            ],
            'sections' => $rows->groupBy('section')->map(fn ($items) => [
                'net' => round((float) $items->sum('net'), 4),
                'rows' => $items->values(),
            ]),
            'quality' => $this->quality(),
        ];
    }

    public function aging(Carbon $asOf, string $kind, ?string $currency = null): array
    {
        $accountKey = $kind === 'payable' ? 'accounts_payable' : 'accounts_receivable';
        $accountId = AccountingAccount::query()->where('system_key', $accountKey)->value('id');
        $rows = $this->lineQuery($currency)
            ->where('lines.account_id', $accountId)
            ->whereDate('entries.entry_date', '<=', $asOf->toDateString())
            ->orderByRaw('COALESCE(lines.due_date, entries.entry_date)')
            ->orderBy('entries.id')
            ->orderBy('lines.id')
            ->get([
                'entries.id as entry_id', 'entries.entry_date', 'entries.currency', 'lines.customer_id', 'lines.seller_id', 'lines.delivery_company_id',
                'lines.due_date', 'lines.debit', 'lines.credit',
            ])
            ->groupBy(fn ($row) => ($row->customer_id
                ? 'customer:'.$row->customer_id
                : ($row->seller_id
                    ? 'seller:'.$row->seller_id
                    : ($row->delivery_company_id ? 'delivery_company:'.$row->delivery_company_id : 'unassigned:0'))).':'.$row->currency)
            ->map(function (Collection $items, string $key) use ($asOf, $kind) {
                [$personType, $personId, $currency] = explode(':', $key, 3);
                $buckets = ['current' => 0.0, '1_30' => 0.0, '31_60' => 0.0, '61_90' => 0.0, 'over_90' => 0.0];
                $charges = [];
                $credits = 0.0;
                foreach ($items as $item) {
                    $amount = (float) $item->debit - (float) $item->credit;
                    if ($kind === 'payable') {
                        $amount *= -1;
                    }
                    if ($amount > 0.0001) {
                        $charges[] = [
                            'remaining' => $amount,
                            'due_date' => Carbon::parse($item->due_date ?: $item->entry_date),
                        ];
                    } elseif ($amount < -0.0001) {
                        $credits += abs($amount);
                    }
                }

                foreach ($charges as &$charge) {
                    if ($credits <= 0.0001) {
                        break;
                    }
                    $allocated = min($credits, $charge['remaining']);
                    $charge['remaining'] -= $allocated;
                    $credits -= $allocated;
                }
                unset($charge);

                foreach ($charges as $charge) {
                    if ($charge['remaining'] <= 0.0001) {
                        continue;
                    }
                    $days = $charge['due_date']->diffInDays($asOf, false);
                    $bucket = $days <= 0 ? 'current' : ($days <= 30 ? '1_30' : ($days <= 60 ? '31_60' : ($days <= 90 ? '61_90' : 'over_90')));
                    $buckets[$bucket] += $charge['remaining'];
                }
                if ($credits > 0.0001) {
                    $buckets['current'] -= $credits;
                }

                $personName = match ($personType) {
                    'customer' => Customer::query()->whereKey((int) $personId)->value('name'),
                    'seller' => Seller::query()->whereKey((int) $personId)->value('name'),
                    'delivery_company' => DeliveryCompany::query()->whereKey((int) $personId)->value('name'),
                    default => 'غير مخصص',
                };

                return [
                    'person_type' => $personType,
                    'person_id' => (int) $personId,
                    'person_name' => $personName ?: 'غير معروف',
                    'currency' => $currency,
                    'current' => round($buckets['current'], 4),
                    'days_1_30' => round($buckets['1_30'], 4),
                    'days_31_60' => round($buckets['31_60'], 4),
                    'days_61_90' => round($buckets['61_90'], 4),
                    'over_90' => round($buckets['over_90'], 4),
                    'balance' => round(array_sum($buckets), 4),
                ];
            })->filter(fn ($row) => abs($row['balance']) > 0.0001)->values();

        return [
            'title' => $kind === 'payable' ? 'أعمار الذمم الدائنة' : 'أعمار الذمم المدينة',
            'as_of' => $asOf->toDateString(),
            'currency' => $currency,
            'summary' => [
                'current' => round((float) $rows->sum('current'), 4),
                'days_1_30' => round((float) $rows->sum('days_1_30'), 4),
                'days_31_60' => round((float) $rows->sum('days_31_60'), 4),
                'days_61_90' => round((float) $rows->sum('days_61_90'), 4),
                'over_90' => round((float) $rows->sum('over_90'), 4),
                'balance' => round((float) $rows->sum('balance'), 4),
            ],
            'rows' => $rows,
            'method' => 'FIFO allocation of credits against oldest due items; due date falls back to journal date.',
            'quality' => $this->quality(),
        ];
    }

    public function journal(Carbon $from, Carbon $to, ?string $currency, int $perPage = 50)
    {
        return $this->journalQuery($from, $to, $currency)
            ->paginate(min(max($perPage, 10), 200));
    }

    public function journalExport(Carbon $from, Carbon $to, ?string $currency): Collection
    {
        return $this->journalQuery($from, $to, $currency)->get();
    }

    private function journalQuery(Carbon $from, Carbon $to, ?string $currency)
    {
        return AccountingJournalEntry::query()
            ->with(['lines.account:id,code,name_ar,type', 'period:id,name,status'])
            ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])
            ->when($currency, fn ($query) => $query->where('currency', $currency))
            ->orderByDesc('entry_date')
            ->orderByDesc('id');
    }

    public function quality(): array
    {
        try {
            return $this->qualitySnapshot();
        } catch (Throwable $e) {
            Log::error('Accounting report quality check failed.', [
                'exception' => $e,
            ]);

            return [
                'complete' => false,
                'quality_check_failed' => true,
                'ledger_empty' => false,
                'cutover_applied' => false,
                'open_failures' => 0,
                'has_unallocated_clearing' => false,
                'cash_lines_without_box' => 0,
                'receivable_payable_lines_without_party' => 0,
                'reconciliation_complete' => false,
                'reconciliation_mismatches' => 0,
            ];
        }
    }

    private function qualitySnapshot(): array
    {
        $openFailures = DB::table('accounting_projection_failures')->whereNull('resolved_at');
        $clearingId = AccountingAccount::query()->where('system_key', 'clearing')->value('id');
        $clearing = $clearingId
            ? DB::table('accounting_journal_lines as lines')
                ->join('accounting_journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
                ->where('lines.account_id', $clearingId)
                ->select('entries.currency')
                ->selectRaw('ROUND(SUM(lines.credit - lines.debit), 4) as balance')
                ->groupBy('entries.currency')
                ->get()
                ->mapWithKeys(fn ($row) => [$row->currency => (float) $row->balance])
                ->all()
            : [];
        $hasClearing = collect($clearing)->contains(fn ($balance) => abs((float) $balance) > 0.0001);
        $entryCount = AccountingJournalEntry::query()->count();
        $cashId = AccountingAccount::query()->where('system_key', 'cash')->value('id');
        $subledgerIds = AccountingAccount::query()
            ->whereIn('system_key', ['accounts_receivable', 'accounts_payable'])
            ->pluck('id');
        $unallocatedCashLines = $cashId
            ? DB::table('accounting_journal_lines as lines')
                ->join('accounting_journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
                ->where('lines.account_id', $cashId)
                ->whereNull('lines.box_id')
                ->select('entries.currency')
                ->groupBy('entries.currency')
                ->havingRaw('ABS(SUM(lines.debit - lines.credit)) > 0.0001')
                ->get()
                ->count()
            : 0;
        $unallocatedPartyLines = DB::table('accounting_journal_lines as lines')
            ->join('accounting_journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->whereIn('lines.account_id', $subledgerIds)
            ->whereNull('lines.customer_id')
            ->whereNull('lines.seller_id')
            ->whereNull('lines.delivery_company_id')
            ->select(['lines.account_id', 'entries.currency'])
            ->groupBy('lines.account_id', 'entries.currency')
            ->havingRaw('ABS(SUM(lines.debit - lines.credit)) > 0.0001')
            ->get()
            ->count();
        $cutover = Schema::hasTable('accounting_cutovers')
            ? DB::table('accounting_cutovers')->where('status', 'applied')->orderByDesc('cutover_date')->orderByDesc('id')->first()
            : null;
        $reconciliation = app(AccountingReconciliationService::class)->reconcile();

        return [
            'complete' => $entryCount > 0
                && $cutover !== null
                && ! (clone $openFailures)->exists()
                && ! $hasClearing
                && $unallocatedCashLines === 0
                && $unallocatedPartyLines === 0
                && $reconciliation['complete'],
            'ledger_empty' => $entryCount === 0,
            'journal_entries' => $entryCount,
            'cutover_applied' => $cutover !== null,
            'coverage_starts_at' => $cutover?->cutover_date,
            'open_failures' => (clone $openFailures)->count(),
            'missing_cost_failures' => (clone $openFailures)->where('error', 'like', '%FIFO cost%')->count(),
            'last_failure_at' => (clone $openFailures)->max('last_failed_at'),
            'clearing_balances' => $clearing,
            'has_unallocated_clearing' => $hasClearing,
            'cash_lines_without_box' => $unallocatedCashLines,
            'receivable_payable_lines_without_party' => $unallocatedPartyLines,
            'reconciliation_complete' => $reconciliation['complete'],
            'reconciliation_mismatches' => $reconciliation['mismatch_count'],
        ];
    }

    private function accountBalances(?Carbon $from, Carbon $to, ?string $currency): Collection
    {
        return $this->totalsQuery($to, $currency)
            ->when($from, fn ($query) => $query->whereDate('entries.entry_date', '>=', $from->toDateString()))
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name_ar', 'accounts.type', 'accounts.normal_balance')
            ->orderBy('accounts.code')
            ->get()
            ->map(fn ($row) => [
                'account_id' => (int) $row->id,
                'code' => $row->code,
                'account' => $row->name_ar,
                'type' => $row->type,
                'debit' => round((float) $row->debit, 4),
                'credit' => round((float) $row->credit, 4),
                'balance' => round((float) $row->debit - (float) $row->credit, 4),
            ])
            ->filter(fn ($row) => abs($row['balance']) > 0.0001)
            ->values();
    }

    private function totalsQuery(Carbon $to, ?string $currency): Builder
    {
        return $this->lineQuery($currency)
            ->join('accounting_accounts as accounts', 'accounts.id', '=', 'lines.account_id')
            ->whereDate('entries.entry_date', '<=', $to->toDateString())
            ->select([
                'accounts.id', 'accounts.code', 'accounts.name_ar', 'accounts.type', 'accounts.normal_balance',
                DB::raw('SUM(lines.debit) as debit'),
                DB::raw('SUM(lines.credit) as credit'),
            ]);
    }

    private function lineQuery(?string $currency): Builder
    {
        return DB::table('accounting_journal_lines as lines')
            ->join('accounting_journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->when($currency, fn ($query) => $query->where('entries.currency', $currency));
    }

    private function period(Carbon $from, Carbon $to): array
    {
        return ['from_date' => $from->toDateString(), 'to_date' => $to->toDateString()];
    }
}
