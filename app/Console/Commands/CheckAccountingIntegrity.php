<?php

namespace App\Console\Commands;

use App\Services\AccountingIntegrityService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CheckAccountingIntegrity extends Command
{
    protected $signature = 'accounting:check-integrity
        {--from= : Include operations on or after this date}
        {--to= : Include operations on or before this date}';

    protected $description = 'Run read-only accounting integrity checks and list operation IDs requiring review';

    public function handle(AccountingIntegrityService $integrity): int
    {
        if (! Schema::hasTable('accounting_journal_entries')) {
            $this->error('Accounting migrations are not applied.');

            return self::FAILURE;
        }

        try {
            $from = $this->option('from') ? Carbon::parse($this->option('from'))->startOfDay() : null;
            $to = $this->option('to') ? Carbon::parse($this->option('to'))->endOfDay() : null;
        } catch (Throwable $exception) {
            $this->error('Invalid date: '.$exception->getMessage());

            return self::INVALID;
        }

        $result = $integrity->run($from, $to);
        $this->table(['Status', 'Check', 'IDs requiring review', 'Meaning'], collect($result['checks'])->map(function (array $check) {
            $ids = collect($check['ids'])->take(30)->implode(', ');
            if (count($check['ids']) > 30) {
                $ids .= ' ... (+'.(count($check['ids']) - 30).')';
            }

            return [$check['status'], $check['name'], $ids ?: '-', $check['message']];
        })->all());

        $summary = $result['summary'];
        $this->table(['PASS', 'WARNING', 'ERROR'], [[
            $summary['pass'], $summary['warning'], $summary['error'],
        ]]);

        return $summary['error'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
