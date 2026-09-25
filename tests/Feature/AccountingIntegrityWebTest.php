<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccountingIntegrityService;
use App\Services\AccountingProjectionRepairService;
use App\Services\AssetDepreciationWorkflowService;
use App\Services\DebtLedgerBalanceRepairService;
use App\Services\LegacyCashAuditService;
use App\Services\MaintenancePrepaymentSyncService;
use App\Services\PurchasePaymentSourceIdentityService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Middleware\ThrottleRequests;
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

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->softDeletes();
        });
        foreach (['accounting_journal_entries', 'accounting_projection_failures', 'inventory_cost_layers', 'inventory_cost_allocations'] as $table) {
            Schema::create($table, fn (Blueprint $blueprint) => $blueprint->id());
        }
        Schema::create('accounting_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('system_key')->unique();
        });
        DB::table('accounting_accounts')->insert([
            ['system_key' => 'service_revenue'],
            ['system_key' => 'cash_overage_income'],
            ['system_key' => 'cash_shortage_expense'],
        ]);
        Schema::create('boxes', function (Blueprint $table) {
            $table->id();
            $table->decimal('total', 14, 4)->default(0);
            $table->string('currency')->default('شيكل');
        });
        Schema::create('box_logs', function (Blueprint $table) {
            $table->id();
            $table->string('reason_code')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
        });
        Schema::create('debt_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('currency')->default('شيكل');
            $table->decimal('balance_after', 14, 4)->default(0);
        });
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
            ->assertSee('الصفحة لا تشغّل Migration')
            ->assertSee('تشغيل الفحص الشامل الآمن')
            ->assertSee('قبل تنفيذ أي إصلاح فعلي على قاعدة الإنتاج');
    }

    public function test_preview_renders_projection_and_integrity_results(): void
    {
        config(['accounting_integrity.web_repair_token' => '']);
        DB::table('boxes')->insert(['id' => 9, 'total' => 1234, 'currency' => 'شيكل']);
        $databaseBefore = $this->financialTableSnapshot();

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

        $debtBalances = Mockery::mock(DebtLedgerBalanceRepairService::class);
        $debtBalances->shouldReceive('run')->once()->with(true)->andReturn($this->debtBalanceResult());
        $this->app->instance(DebtLedgerBalanceRepairService::class, $debtBalances);

        $legacyCashAudit = Mockery::mock(LegacyCashAuditService::class);
        $legacyCashAudit->shouldReceive('audit')->once()->withNoArgs()->andReturn($this->legacyCashAuditResult());
        $this->app->instance(LegacyCashAuditService::class, $legacyCashAudit);

        $purchasePaymentSources = Mockery::mock(PurchasePaymentSourceIdentityService::class);
        $purchasePaymentSources->shouldReceive('inspect')->once()->withNoArgs()->andReturn(collect($this->purchasePaymentSourceItems()));
        $purchasePaymentSources->shouldNotReceive('run');
        $this->app->instance(PurchasePaymentSourceIdentityService::class, $purchasePaymentSources);

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
            ->assertSee('فحص أرصدة دفتر الديون — قراءة فقط')
            ->assertSee('PASS 1')
            ->assertSee('تدقيق حركات النقد القديمة')
            ->assertSee('UNLINKED_CASH')
            ->assertSee('AMBIGUOUS')
            ->assertSee('DUPLICATE_ACCOUNTING_RISK')
            ->assertSee('#501')
            ->assertSee('#502')
            ->assertSee('#503')
            ->assertDontSee('#504')
            ->assertSee('فحص هوية مصدر دفعات الشراء')
            ->assertSee('ALREADY_CORRECT')
            ->assertSee('SAFE_TO_REPAIR')
            ->assertSee('#701')
            ->assertSee('#702');

        $this->assertSame($databaseBefore, $this->financialTableSnapshot());
    }

    public function test_every_existing_write_action_still_requires_token_and_confirmation(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        foreach ([
            '/security-center/accounting/repair',
            '/security-center/accounting/maintenance-prepayments/sync',
            '/security-center/accounting/assets/depreciation/run',
            '/security-center/accounting/debt-ledger/balances/repair',
        ] as $uri) {
            $this->withSession(['security_center_authenticated' => true])
                ->from('/security-center/accounting')
                ->post($uri)
                ->assertRedirect('/security-center/accounting')
                ->assertSessionHasErrors(['access_token', 'confirmation']);
        }
    }

    public function test_confirmed_debt_balance_repair_updates_only_through_the_safe_workflow(): void
    {
        $debtBalances = Mockery::mock(DebtLedgerBalanceRepairService::class);
        $debtBalances->shouldReceive('run')->once()->with(false)->andReturn($this->debtBalanceResult(false));
        $this->app->instance(DebtLedgerBalanceRepairService::class, $debtBalances);

        $integrity = Mockery::mock(AccountingIntegrityService::class);
        $integrity->shouldReceive('run')->once()->with(null, null)->andReturn($this->integrityResult());
        $this->app->instance(AccountingIntegrityService::class, $integrity);

        $this->withSession(['security_center_authenticated' => true])
            ->post('/security-center/accounting/debt-ledger/balances/repair', [
                'access_token' => 'accounting-secret',
                'confirmation' => 'إصلاح أرصدة دفتر الديون',
            ])
            ->assertOk()
            ->assertSee('اكتمل تصحيح الحقل المشتق balance_after فقط')
            ->assertSee('صفوف balance_after المصححة');
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
        DB::table('users')->insert(['id' => 15, 'name' => 'Accounting operator']);
        $user = User::query()->findOrFail(15);
        $depreciation = Mockery::mock(AssetDepreciationWorkflowService::class);
        $depreciation->shouldReceive('run')->once()->with('2026-09', 15)->andReturn([
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

        $this->actingAs($user)
            ->withSession(['security_center_authenticated' => true])
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

    /** @return array<string, mixed> */
    private function debtBalanceResult(bool $dryRun = true): array
    {
        return [
            'dry_run' => $dryRun,
            'summary' => [
                'total_issues' => 1,
                'affected_groups' => 1,
                'repaired_rows' => $dryRun ? 0 : 1,
                'remaining_issues' => $dryRun ? 1 : 0,
                'accounting_mismatches' => 0,
            ],
            'items' => [[
                'person_type' => 'customer',
                'person_id' => 8,
                'currency' => 'شيكل',
                'transaction_id' => 22,
                'stored_balance' => 150,
                'expected_balance' => 100,
                'difference' => 50,
            ]],
            'remaining_items' => [],
            'reconciliation' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function legacyCashAuditResult(): array
    {
        $rows = [
            $this->legacyCashRow(501, 'UNLINKED_CASH', 'لا يوجد مصدر مالي معروف.'),
            $this->legacyCashRow(502, 'AMBIGUOUS', 'وجد أكثر من مصدر محتمل.'),
            $this->legacyCashRow(503, 'DUPLICATE_ACCOUNTING_RISK', 'النقد مرحل من مصدر آخر.'),
            $this->legacyCashRow(504, 'LINKED_AND_ACCOUNTED', 'المصدر والقيد متطابقان.'),
        ];

        return [
            'summary' => [
                'total_rows' => 4,
                'linked_and_accounted' => 1,
                'linked_not_accounted' => 0,
                'linked_but_reversed' => 0,
                'duplicate_accounting_risk' => 1,
                'unlinked_cash' => 1,
                'ambiguous' => 1,
            ],
            'rows' => $rows,
        ];
    }

    /** @return array<string, mixed> */
    private function legacyCashRow(int $id, string $classification, string $reason): array
    {
        return [
            'box_log_id' => $id,
            'date' => '2026-09-20 10:00:00',
            'box_id' => 9,
            'currency' => 'شيكل',
            'type' => 'out',
            'amount' => 100,
            'description' => 'حركة نقد تجريبية',
            'reason_code' => null,
            'matched_source_type' => $classification === 'UNLINKED_CASH' ? null : 'debt_transaction',
            'matched_source_id' => $classification === 'UNLINKED_CASH' ? null : 88,
            'journal_entry_id' => $classification === 'DUPLICATE_ACCOUNTING_RISK' ? 40 : null,
            'classification' => $classification,
            'reason' => $reason,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function purchasePaymentSourceItems(): array
    {
        return [
            [
                'purchase_payment_id' => 701,
                'bill_id' => 100,
                'debt_transaction_id' => 801,
                'current_source' => 'purchase_payment',
                'current_source_id' => 701,
                'expected_source' => 'purchase_payment',
                'expected_source_id' => 701,
                'identity_conflict' => false,
                'status' => 'ALREADY_CORRECT',
            ],
            [
                'purchase_payment_id' => 702,
                'bill_id' => 100,
                'debt_transaction_id' => 802,
                'current_source' => 'purchase_payment',
                'current_source_id' => 100,
                'expected_source' => 'purchase_payment',
                'expected_source_id' => 702,
                'identity_conflict' => false,
                'status' => 'SAFE_TO_REPAIR',
            ],
            [
                'purchase_payment_id' => 703,
                'bill_id' => 101,
                'debt_transaction_id' => 803,
                'current_source' => 'purchase_initial_payment',
                'current_source_id' => 101,
                'expected_source' => 'purchase_initial_payment',
                'expected_source_id' => 703,
                'identity_conflict' => true,
                'status' => 'AMBIGUOUS',
            ],
        ];
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function financialTableSnapshot(): array
    {
        return collect(['boxes', 'box_logs', 'debt_transactions', 'accounting_journal_entries'])
            ->mapWithKeys(fn (string $table) => [
                $table => DB::table($table)->orderBy('id')->get()->map(fn (object $row) => (array) $row)->all(),
            ])
            ->all();
    }
}
