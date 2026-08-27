<?php

namespace App\Console\Commands;

use App\Services\MonthlyAssetDepreciationService;
use Illuminate\Console\Command;

class RunMonthlyAssetDepreciation extends Command
{
    protected $signature = 'assets:run-monthly-depreciation {--period=}';
    protected $description = 'Run idempotent monthly depreciation for all active assets';

    public function handle(MonthlyAssetDepreciationService $service): int
    {
        $result = $service->run($this->option('period'));
        $this->info("Processed {$result['processed']} asset(s); skipped {$result['skipped']}.");

        return self::SUCCESS;
    }
}
