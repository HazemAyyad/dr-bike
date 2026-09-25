<?php

namespace App\Console\Commands;

use App\Services\LegacyCashAuditService;
use Illuminate\Console\Command;

class AuditLegacyCash extends Command
{
    protected $signature = 'accounting:audit-legacy-cash';

    protected $description = 'Read-only audit of legacy BoxLog cash movements and their accounting sources';

    public function handle(LegacyCashAuditService $audit): int
    {
        $result = $audit->audit();

        $this->table(
            ['BoxLog ID', 'Date', 'Box ID', 'Currency', 'Type', 'Amount', 'Description', 'Reason Code', 'Matched Source Type', 'Matched Source ID', 'Journal Entry ID', 'Classification', 'Reason'],
            collect($result['rows'])->map(fn (array $row) => [
                $row['box_log_id'],
                $row['date'] ?? '-',
                $row['box_id'] ?? '-',
                $row['currency'] ?? '-',
                $row['type'] ?? '-',
                number_format((float) $row['amount'], 4, '.', ''),
                $row['description'] ?? '-',
                $row['reason_code'] ?? '-',
                $row['matched_source_type'] ?? '-',
                $row['matched_source_id'] ?? '-',
                $row['journal_entry_id'] ?? '-',
                $row['classification'],
                $row['reason'],
            ])->all(),
        );

        $this->newLine();
        $this->info('Summary');
        foreach ($result['summary'] as $key => $value) {
            $this->line($key.': '.$value);
        }
        $this->warn('READ ONLY: no BoxLog, box, debt, journal, or source data was changed.');

        return self::SUCCESS;
    }
}
