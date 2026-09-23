<?php

namespace Tests\Feature;

use App\Services\AccountingIntegrityService;
use App\Services\AccountingProjectionRepairService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class AccountingIntegrityWebTest extends TestCase
{
    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = (string) config('database.default');
        config([
            'accounting_integrity.web_repair_token' => 'accounting-secret',
            'database.default' => 'accounting_web_test',
            'database.connections.accounting_web_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('accounting_web_test');
        DB::setDefaultConnection('accounting_web_test');

        foreach (['accounting_journal_entries', 'accounting_projection_failures', 'inventory_cost_layers', 'inventory_cost_allocations'] as $table) {
            Schema::create($table, fn (Blueprint $blueprint) => $blueprint->id());
        }
        Schema::create('accounting_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('system_key')->unique();
        });
        DB::table('accounting_accounts')->insert(['system_key' => 'service_revenue']);
    }

    protected function tearDown(): void
    {
        DB::purge('accounting_web_test');
        DB::setDefaultConnection($this->originalConnection);
        parent::tearDown();
    }

    public function test_accounting_page_requires_security_center_login(): void
    {
        $this->get('/security-center/accounting')
            ->assertRedirect('/security-center/login');
    }

    public function test_authenticated_operator_can_open_accounting_page(): void
    {
        $this->withSession(['security_center_authenticated' => true])
            ->get('/security-center/accounting')
            ->assertOk()
            ->assertSee('سلامة المحاسبة')
            ->assertSee('الصفحة لا تشغّل Migration');
    }

    public function test_preview_renders_projection_and_integrity_results(): void
    {
        $repair = Mockery::mock(AccountingProjectionRepairService::class);
        $repair->shouldReceive('run')->once()->with(true)->andReturn($this->repairResult());
        $this->app->instance(AccountingProjectionRepairService::class, $repair);

        $integrity = Mockery::mock(AccountingIntegrityService::class);
        $integrity->shouldReceive('run')->once()->withArgs(function (?Carbon $from, ?Carbon $to) {
            return $from?->toDateString() === '2026-09-21' && $to?->toDateString() === '2026-09-23';
        })->andReturn($this->integrityResult());
        $this->app->instance(AccountingIntegrityService::class, $integrity);

        $this->withSession(['security_center_authenticated' => true])
            ->post('/security-center/accounting/inspect', [
                'from' => '2026-09-21',
                'to' => '2026-09-23',
            ])
            ->assertOk()
            ->assertSee('اكتملت المعاينة بوضع القراءة فقط')
            ->assertSee('instant_sale:12')
            ->assertSee('PASS 1');
    }

    public function test_repair_rejects_wrong_token_without_calling_services(): void
    {
        $repair = Mockery::mock(AccountingProjectionRepairService::class);
        $repair->shouldNotReceive('run');
        $this->app->instance(AccountingProjectionRepairService::class, $repair);

        $integrity = Mockery::mock(AccountingIntegrityService::class);
        $integrity->shouldNotReceive('run');
        $this->app->instance(AccountingIntegrityService::class, $integrity);

        $this->withSession(['security_center_authenticated' => true])
            ->from('/security-center/accounting')
            ->post('/security-center/accounting/repair', [
                'access_token' => 'wrong',
                'confirmation' => 'إصلاح القيود',
            ])
            ->assertRedirect('/security-center/accounting')
            ->assertSessionHasErrors('access_token');
    }

    public function test_confirmed_repair_runs_once_then_performs_read_only_follow_up(): void
    {
        $repair = Mockery::mock(AccountingProjectionRepairService::class);
        $repair->shouldReceive('run')->once()->with(false)->andReturn($this->repairResult('successfully_repaired'));
        $repair->shouldReceive('run')->once()->with(true)->andReturn($this->repairResult('still_failing'));
        $this->app->instance(AccountingProjectionRepairService::class, $repair);

        $integrity = Mockery::mock(AccountingIntegrityService::class);
        $integrity->shouldReceive('run')->once()->with(null, null)->andReturn($this->integrityResult());
        $this->app->instance(AccountingIntegrityService::class, $integrity);

        $this->withSession(['security_center_authenticated' => true])
            ->post('/security-center/accounting/repair', [
                'access_token' => 'accounting-secret',
                'confirmation' => 'إصلاح القيود',
            ])
            ->assertOk()
            ->assertSee('اكتملت محاولة الإصلاح')
            ->assertSee('المتبقي بعد الإصلاح');
    }

    /** @return array<string, mixed> */
    private function repairResult(string $status = 'repairable'): array
    {
        $summary = [
            'total_failures' => 1,
            'repairable' => $status === 'repairable' ? 1 : 0,
            'successfully_repaired' => $status === 'successfully_repaired' ? 1 : 0,
            'still_failing' => $status === 'still_failing' ? 1 : 0,
            'already_valid_or_stale' => 0,
            'skipped' => 0,
        ];

        return [
            'summary' => $summary,
            'items' => [[
                'failure_id' => 7,
                'source_type' => 'instant_sale',
                'source_id' => 12,
                'previous_error' => 'Missing FIFO cost.',
                'repairable' => $status !== 'still_failing',
                'status' => $status,
                'message' => 'Read-only assessment.',
                'issues' => [],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function integrityResult(): array
    {
        return [
            'summary' => ['pass' => 1, 'warning' => 0, 'error' => 0],
            'checks' => [[
                'name' => 'journal_balance',
                'status' => 'PASS',
                'message' => 'All journals are balanced.',
                'ids' => [],
                'details' => [],
            ]],
        ];
    }
}
