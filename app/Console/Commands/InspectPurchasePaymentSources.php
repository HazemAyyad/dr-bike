<?php

namespace App\Console\Commands;

use App\Services\PurchasePaymentSourceIdentityService;
use Illuminate\Console\Command;

class InspectPurchasePaymentSources extends Command
{
    protected $signature = 'accounting:inspect-purchase-payment-sources
        {--dry-run : Explicitly run the read-only inspection}
        {--repair : Repair only source_id when the direct payment link proves the identity}';

    protected $description = 'Inspect purchase payment debt source identities without guessing legacy links';

    public function handle(PurchasePaymentSourceIdentityService $service): int
    {
        if ($this->option('dry-run') && $this->option('repair')) {
            $this->error('Choose either --dry-run or --repair, not both.');

            return self::INVALID;
        }

        $result = $service->run((bool) $this->option('repair'));
        $this->table(
            ['Payment', 'Bill', 'Debt transaction', 'Current source', 'Current ID', 'Expected source', 'Expected ID', 'Status'],
            collect($result['items'])->map(fn (array $item) => [
                $item['purchase_payment_id'],
                $item['bill_id'] ?? '-',
                $item['debt_transaction_id'] ?? '-',
                $item['current_source'] ?? '-',
                $item['current_source_id'] ?? '-',
                $item['expected_source'],
                $item['expected_source_id'],
                $item['status'],
            ])->all(),
        );

        foreach ($result['summary'] as $key => $value) {
            $this->line($key.': '.$value);
        }
        if (! $this->option('repair')) {
            $this->warn('READ ONLY: no debt, payment, cash, or journal data was changed.');
        }

        return self::SUCCESS;
    }
}
