<?php

namespace App\Console\Commands;

use App\Models\Debt;
use App\Models\DebtTransaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateDebtsToLedger extends Command
{
    protected $signature = 'debts:migrate-to-ledger';

    protected $description = 'Migrate existing debts records into debt_transactions ledger entries';

    public function handle(): int
    {
        $debts = Debt::query()->orderBy('id')->get();
        $created = 0;
        $skipped = 0;

        foreach ($debts as $debt) {
            $exists = DebtTransaction::query()
                ->where('source', 'old_debt_migration')
                ->where('source_id', $debt->id)
                ->exists();

            if ($exists) {
                $skipped++;
                continue;
            }

            if (!$debt->customer_id && !$debt->seller_id) {
                $skipped++;
                continue;
            }

            $type = $debt->type === 'owed to us' ? 'taken' : 'given';

            DB::transaction(function () use ($debt, $type, &$created) {
                $customerId = $debt->customer_id;
                $sellerId = $debt->seller_id;

                $previousBalance = (float) DebtTransaction::query()
                    ->active()
                    ->when($customerId, fn ($q) => $q->where('customer_id', $customerId)->whereNull('seller_id'))
                    ->when($sellerId, fn ($q) => $q->where('seller_id', $sellerId)->whereNull('customer_id'))
                    ->orderByDesc('transaction_date')
                    ->orderByDesc('id')
                    ->value('balance_after') ?? 0;

                $amount = (float) $debt->total;
                $balanceAfter = $type === 'taken'
                    ? $previousBalance + $amount
                    : $previousBalance - $amount;

                DebtTransaction::create([
                    'customer_id' => $customerId,
                    'seller_id' => $sellerId,
                    'type' => $type,
                    'amount' => $amount,
                    'balance_after' => $balanceAfter,
                    'note' => $debt->notes,
                    'receipt_images' => $debt->receipt_image,
                    'transaction_date' => $debt->due_date ?? $debt->created_at?->format('Y-m-d'),
                    'source' => 'old_debt_migration',
                    'source_id' => $debt->id,
                    'created_at' => $debt->created_at,
                    'updated_at' => $debt->updated_at,
                ]);

                $created++;
            });
        }

        $this->info("Migration complete. Created: {$created}, Skipped: {$skipped}");

        return self::SUCCESS;
    }
}
