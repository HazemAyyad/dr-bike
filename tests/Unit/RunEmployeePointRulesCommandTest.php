<?php

namespace Tests\Unit;

use App\Services\EmployeePointRules\EmployeePointRuleEngineService;
use Carbon\Carbon;
use Mockery;
use Tests\TestCase;

class RunEmployeePointRulesCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_automatic_run_evaluates_the_completed_previous_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-07 02:10:00', 'Asia/Hebron'));

        $engine = Mockery::mock(EmployeePointRuleEngineService::class);
        $engine->shouldReceive('run')
            ->once()
            ->withArgs(function (Carbon $anchor, ?int $ruleId, bool $force): bool {
                return $anchor->toDateString() === '2026-09-06'
                    && $ruleId === null
                    && $force === false;
            })
            ->andReturn([
                'rules' => 1,
                'employees' => 1,
                'awarded' => 0,
                'deducted' => 0,
                'zero' => 0,
                'skipped' => 1,
            ]);
        $this->app->instance(EmployeePointRuleEngineService::class, $engine);

        $this->artisan('employee-points:run-rules')->assertSuccessful();
    }

    public function test_explicit_date_is_still_used_for_manual_backfill(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-07 02:10:00', 'Asia/Hebron'));

        $engine = Mockery::mock(EmployeePointRuleEngineService::class);
        $engine->shouldReceive('run')
            ->once()
            ->withArgs(function (Carbon $anchor, ?int $ruleId, bool $force): bool {
                return $anchor->toDateString() === '2026-09-03'
                    && $ruleId === 2
                    && $force === true;
            })
            ->andReturn([
                'rules' => 1,
                'employees' => 1,
                'awarded' => 0,
                'deducted' => 1,
                'zero' => 0,
                'skipped' => 0,
            ]);
        $this->app->instance(EmployeePointRuleEngineService::class, $engine);

        $this->artisan('employee-points:run-rules', [
            '--date' => '2026-09-03',
            '--rule' => 2,
            '--force' => true,
        ])->assertSuccessful();
    }
}
