<?php

namespace App\Console\Commands;

use App\Services\EmployeePointRules\EmployeePointRuleEngineService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RunEmployeePointRules extends Command
{
    protected $signature = 'employee-points:run-rules
        {--date= : Anchor date, for example 2026-08-09}
        {--rule= : Run one rule id only}
        {--force : Delete and recreate existing rule logs for the same period}';

    protected $description = 'Evaluate automatic employee point rules.';

    public function handle(EmployeePointRuleEngineService $engine): int
    {
        $anchor = $this->option('date')
            ? Carbon::parse((string) $this->option('date'))
            : Carbon::now();

        $ruleId = $this->option('rule') !== null ? (int) $this->option('rule') : null;
        $summary = $engine->run($anchor, $ruleId, (bool) $this->option('force'));

        $this->info(sprintf(
            'Rules: %d, employees: %d, awarded: %d, deducted: %d, zero: %d, skipped: %d',
            $summary['rules'],
            $summary['employees'],
            $summary['awarded'],
            $summary['deducted'],
            $summary['zero'],
            $summary['skipped'],
        ));

        return self::SUCCESS;
    }
}
