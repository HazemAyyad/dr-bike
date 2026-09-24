<?php

namespace Tests\Feature;

use App\Services\AccountingIntegrityService;
use App\Services\AccountingProjectionRepairService;
use App\Services\AssetDepreciationWorkflowService;
use App\Services\MaintenancePrepaymentSyncService;
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
        Schema::create('maintenance_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_stage')->nullable();
        });
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('months_number')->default(1);
            $table->decimal('depreciation_price', 14, 4)->default(0);
        });
        Schema::create('asset_logs', function (Blueprint $table) {
            $table->id();
            $table->string('depreciation_period')->nullable();
            $table->decimal('depreciation_amount', 14, 4)->default(0);
        });
    }

    protected function tearDown(): void
    {
        DB::purge('accounting_web_test');
        DB::setDefaultConnection($this->originalConnection);
        Carbon::setTestNow();
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

        $prepayments = Mockery::mock(MaintenancePrepaymentSyncService::class);
        $prepayments->shouldReceive('run')->once()->with(true)->andReturn($this->prepaymentResult());
        $this->app->instance(MaintenancePrepaymentSyncService::class, $prepayments);

        $depreciation = Mockery::mock(AssetDepreciationWorkflowService::class);
        $depreciation->shouldReceive('preview')->once()->with('2026-09')->andReturn($this->depreciationPreview());
        $this->app->instance(AssetDepreciationWorkflowService::class, $depreciation);

        $this->withSession(['security_center_authenticated' => true])
            ->post('/security-center/accounting/inspect', [
                'from' => '2026-09-21',
                'to' => '2026-09-23',
                'depreciation_period' => '2026-09',
            ])
            ->assertOk()
            ->assertSee('اكتملت المعاينة بوضع القراءة فقط')
            ->assertSee('instant_sale:12')
            ->assertSee('جاهزة للترحيل كعربون صيانة')
            ->assertSee('أصل تجريبي')
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

    public function test_maintenance_prepayment_sync_rejects_wrong_token_without_writing(): void
    {
        $prepayments = Mockery::mock(MaintenancePrepaymentSyncService::class);
        $prepayments->shouldNotReceive('run');
        $this->app->instance(MaintenancePrepaymentSyncService::class, $prepayments);

        $this->withSession(['security_center_authenticated' => true])
            ->from('/security-center/accounting')
            ->post('/security-center/accounting/maintenance-prepayments/sync', [
                'access_token' => 'wrong',
                'confirmation' => 'ترحيل عربونات الصيانة',
            ])
            ->assertRedirect('/security-center/accounting')
            ->assertSessionHasErrors('access_token');
    }

    public function test_confirmed_maintenance_prepayment_sync_runs_once_then_previews_remaining(): void
    {
        $prepayments = Mockery::mock(MaintenancePrepaymentSyncService::class);
        $prepayments->shouldReceive('run')->once()->with(false)->andReturn($this->prepaymentResult('projected'));
        $prepayments->shouldReceive('run')->once()->with(true)->andReturn($this->prepaymentResult('already_posted'));
        $this->app->instance(MaintenancePrepaymentSyncService::class, $prepayments);

        $integrity = Mockery::mock(AccountingIntegrityService::class);
        $integrity->shouldReceive('run')->once()->with(null, null)->andReturn($this->integrityResult());
        $this->app->instance(AccountingIntegrityService::class, $integrity);

        $this->withSession(['security_center_authenticated' => true])
            ->post('/security-center/accounting/maintenance-prepayments/sync', [
                'access_token' => 'accounting-secret',
                'confirmation' => 'ترحيل عربونات الصيانة',
            ])
            ->assertOk()
            ->assertSee('اكتمل ترحيل عربونات الصيانة')
            ->assertSee('حالة العربونات بعد التنفيذ');
    }

    public function test_asset_depreciation_web_run_is_limited_to_current_month(): void
    {
        Carbon::setTestNow('2026-09-24 12:00:00');
        $depreciation = Mockery::mock(AssetDepreciationWorkflowService::class);
        $depreciation->shouldNotReceive('run');
        $this->app->instance(AssetDepreciationWorkflowService::class, $depreciation);

        $this->withSession(['security_center_authenticated' => true])
            ->from('/security-center/accounting')
            ->post('/security-center/accounting/assets/depreciation/run', [
                'access_token' => 'accounting-secret',
                'confirmation' => 'تنفيذ إهلاك الأصول',
                'depreciation_period' => '2026-08',
            ])
            ->assertRedirect('/security-center/accounting')
            ->assertSessionHasErrors('depreciation_period');
    }

    public function test_confirmed_asset_depreciation_runs_current_month_and_checks_integrity(): void
    {
        Carbon::setTestNow('2026-09-24 12:00:00');
        $depreciation = Mockery::mock(AssetDepreciationWorkflowService::class);
        $depreciation->shouldReceive('run')->once()->with('2026-09', null)->andReturn([
            'before' => $this->depreciationPreview(),
            'execution' => ['processed' => 1, 'skipped' => 0, 'warnings' => []],
            'after' => $this->depreciationPreview('already_depreciated'),
        ]);
        $this->app->instance(AssetDepreciationWorkflowService::class, $depreciation);

        $integrity = Mockery::mock(AccountingIntegrityService::class);
        $integrity->shouldReceive('run')->once()->withArgs(function (?Carbon $from, ?Carbon $to) {
            return $from?->toDateString() === '2026-09-01' && $to?->toDateString() === '2026-09-30';
        })->andReturn($this->integrityResult());
        $this->app->instance(AccountingIntegrityService::class, $integrity);

        $this->withSession(['security_center_authenticated' => true])
            ->post('/security-center/accounting/assets/depreciation/run', [
                'access_token' => 'accounting-secret',
                'confirmation' => 'تنفيذ إهلاك الأصول',
                'depreciation_period' => '2026-09',
            ])
            ->assertOk()
            ->assertSee('اكتمل تنفيذ إهلاك الشهر المحدد')
            ->assertSee('نُفذ الإهلاك على 1 أصل');
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

    /** @return array<string, mixed> */
    private function prepaymentResult(string $status = 'ready'): array
    {
        return [
            'summary' => [
                'total' => 1,
                'already_posted' => $status === 'already_posted' ? 1 : 0,
                'ready' => $status === 'ready' ? 1 : 0,
                'projected' => $status === 'projected' ? 1 : 0,
                'failed' => 0,
                'skipped' => 0,
            ],
            'items' => [[
                'payment_id' => 18,
                'maintenance_id' => 150,
                'amount' => 50,
                'currency' => 'شيكل',
                'box_id' => 103,
                'status' => $status,
                'message' => $status === 'ready'
                    ? 'جاهزة للترحيل كعربون صيانة.'
                    : 'نتيجة تنفيذ تجريبية.',
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function depreciationPreview(string $status = 'eligible'): array
    {
        $eligible = $status === 'eligible';

        return [
            'period' => '2026-09',
            'summary' => [
                'total' => 1,
                'eligible' => $eligible ? 1 : 0,
                'already_depreciated' => $status === 'already_depreciated' ? 1 : 0,
                'requires_review' => 0,
                'skipped' => $eligible ? 0 : 1,
                'depreciation_amount' => $eligible ? 100 : 0,
            ],
            'items' => [[
                'asset_id' => 1,
                'name' => 'أصل تجريبي',
                'current_book_value' => $eligible ? 1200 : 1100,
                'useful_life_months' => 12,
                'used_periods' => $eligible ? 0 : 1,
                'remaining_periods' => $eligible ? 12 : 11,
                'depreciation_amount' => $eligible ? 100 : 0,
                'value_after' => 1100,
                'status' => $status,
                'warning' => null,
                'skip_reason' => $eligible ? null : 'تم إهلاك الأصل لهذه الفترة.',
            ]],
        ];
    }
}
