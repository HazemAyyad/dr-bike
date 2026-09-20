<?php

namespace App\Console\Commands;

use App\Services\AccountingReconciliationService;
use App\Services\AccountingReportService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuditAccountingLedger extends Command
{
    protected $signature = 'accounting:audit {--from=} {--to=} {--currency=}';

    protected $description = 'Read-only accounting ledger integrity and data-quality audit';

    public function handle(AccountingReportService $reports, AccountingReconciliationService $reconciliationService): int
    {
        if (! Schema::hasTable('accounting_journal_entries')) {
            $this->error('Accounting migrations are not applied.');

            return self::FAILURE;
        }

        $from = Carbon::parse($this->option('from') ?: '1900-01-01');
        $to = Carbon::parse($this->option('to') ?: now());
        $currency = $this->option('currency') ?: null;
        $trial = $reports->trialBalance($from, $to, $currency);
        $quality = $reports->quality();
        $reconciliation = $reconciliationService->reconcile();
        $unbalancedEntries = DB::table('accounting_journal_entries as entries')
            ->join('accounting_journal_lines as lines', 'lines.journal_entry_id', '=', 'entries.id')
            ->groupBy('entries.id')
            ->havingRaw('ABS(SUM(lines.debit) - SUM(lines.credit)) > 0.0001')
            ->count();

        $this->table(['Check', 'Value'], [
            ['Trial balance debit', $trial['summary']['debit']],
            ['Trial balance credit', $trial['summary']['credit']],
            ['Trial balance difference', $trial['summary']['difference']],
            ['Unbalanced entries', $unbalancedEntries],
            ['Open projection failures', $quality['open_failures']],
            ['Missing cost failures', $quality['missing_cost_failures']],
            ['Unallocated clearing', $quality['has_unallocated_clearing'] ? 'yes' : 'no'],
            ['Reconciliation mismatches', $reconciliation['mismatch_count']],
        ]);

        return $trial['summary']['balanced'] && $unbalancedEntries === 0 && $quality['complete'] && $reconciliation['complete']
            ? self::SUCCESS
            : self::FAILURE;
    }
}
