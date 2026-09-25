<?php

namespace Tests\Unit;

use App\Services\LegacyCashAuditService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LegacyCashAuditServiceTest extends TestCase
{
    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = (string) config('database.default');
        config([
            'database.default' => 'legacy_cash_audit_test',
            'database.connections.legacy_cash_audit_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('legacy_cash_audit_test');
        DB::setDefaultConnection('legacy_cash_audit_test');

        Schema::create('boxes', function (Blueprint $table) {
            $table->id();
            $table->string('currency')->nullable();
        });
        Schema::create('box_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('from_box_id')->nullable();
            $table->unsignedBigInteger('to_box_id')->nullable();
            $table->unsignedBigInteger('box_id')->nullable();
            $table->decimal('value', 14, 4)->default(0);
            $table->decimal('transfered_balance', 14, 4)->default(0);
            $table->string('type')->nullable();
            $table->text('description')->nullable();
            $table->text('note')->nullable();
            $table->string('reason_code')->nullable();
            $table->timestamps();
        });
        Schema::create('instant_sales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_box_id')->nullable();
            $table->decimal('payment_box_value', 14, 4)->default(0);
            $table->string('status')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });
        Schema::create('purchase_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('box_id')->nullable();
            $table->unsignedBigInteger('box_log_id')->nullable();
            $table->decimal('amount', 14, 4)->default(0);
            $table->date('paid_at')->nullable();
            $table->timestamps();
        });
        Schema::create('maintenance_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('box_id')->nullable();
            $table->decimal('amount', 14, 4)->default(0);
            $table->string('method')->nullable();
            $table->timestamps();
        });
        Schema::create('sales_order_settlements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('box_id')->nullable();
            $table->decimal('cash_amount', 14, 4)->default(0);
            $table->string('source')->nullable();
            $table->timestamps();
        });
        Schema::create('debt_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('box_id')->nullable();
            $table->decimal('amount', 14, 4)->default(0);
            $table->string('type');
            $table->date('transaction_date');
            $table->string('source')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
        Schema::create('accounting_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('system_key');
        });
        Schema::create('accounting_journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->date('entry_date');
            $table->string('currency');
            $table->string('status');
            $table->unsignedBigInteger('reverses_entry_id')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
        });
        Schema::create('accounting_journal_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('journal_entry_id');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('box_id')->nullable();
            $table->decimal('debit', 14, 4)->default(0);
            $table->decimal('credit', 14, 4)->default(0);
        });

        DB::table('boxes')->insert(['id' => 1, 'currency' => 'شيكل']);
        DB::table('accounting_accounts')->insert(['id' => 1, 'system_key' => 'cash']);
    }

    protected function tearDown(): void
    {
        DB::disconnect('legacy_cash_audit_test');
        DB::setDefaultConnection($this->originalConnection);
        DB::purge('legacy_cash_audit_test');
        parent::tearDown();
    }

    public function test_it_classifies_legacy_cash_without_writing_any_data(): void
    {
        $this->boxLog(1, 100, 'add', 'تم اضافة رصيد للصندوق', 'owner_contribution');
        $this->boxLog(2, 200, 'add', 'قبض — بيع فوري #10');
        $this->boxLog(3, -300, 'minus', 'سحب — دفع من الصندوق');
        $this->boxLog(4, 400, 'add', 'قبض نقدي غير مرتبط');
        $this->boxLog(5, 500, 'add', 'قبض نقدي يحتاج مراجعة');

        DB::table('instant_sales')->insert([
            'id' => 10,
            'payment_box_id' => 1,
            'payment_box_value' => 200,
            'created_at' => '2026-09-01 10:01:00',
            'updated_at' => '2026-09-01 10:01:00',
        ]);
        $this->cashJournal(1, 'box_adjustment', 1, 100, 0);
        $this->cashJournal(2, 'expense', 20, 0, 300);
        $this->cashJournal(3, 'instant_sale', 30, 500, 0);
        $this->cashJournal(4, 'debt_transaction', 40, 500, 0);

        $before = $this->databaseSnapshot();
        $result = app(LegacyCashAuditService::class)->audit();
        $after = $this->databaseSnapshot();

        $this->assertSame($before, $after);
        $this->assertSame(LegacyCashAuditService::LINKED_AND_ACCOUNTED, $this->classification($result, 1));
        $this->assertSame(LegacyCashAuditService::LINKED_NOT_ACCOUNTED, $this->classification($result, 2));
        $this->assertSame(LegacyCashAuditService::DUPLICATE_ACCOUNTING_RISK, $this->classification($result, 3));
        $this->assertSame(LegacyCashAuditService::UNLINKED_CASH, $this->classification($result, 4));
        $this->assertSame(LegacyCashAuditService::AMBIGUOUS, $this->classification($result, 5));
        $this->assertSame([
            'total_rows' => 5,
            'linked_and_accounted' => 1,
            'linked_not_accounted' => 1,
            'linked_but_reversed' => 0,
            'duplicate_accounting_risk' => 1,
            'unlinked_cash' => 1,
            'ambiguous' => 1,
        ], $result['summary']);
    }

    public function test_command_has_no_repair_option_and_reports_read_only_summary(): void
    {
        $this->boxLog(1, 75, 'add', 'قبض نقدي غير مرتبط');
        $before = $this->tableCounts();

        $this->artisan('accounting:audit-legacy-cash')
            ->expectsOutputToContain('UNLINKED_CASH')
            ->expectsOutputToContain('total_rows: 1')
            ->expectsOutputToContain('READ ONLY')
            ->assertSuccessful();

        $this->assertSame($before, $this->tableCounts());
        $this->assertStringNotContainsString('--repair', Artisan::all()['accounting:audit-legacy-cash']->getSynopsis());
    }

    public function test_it_reads_legacy_transfered_balance_and_infers_direction_without_type(): void
    {
        DB::table('box_logs')->insert([
            'id' => 1,
            'box_id' => 1,
            'value' => 0,
            'transfered_balance' => 80,
            'type' => null,
            'description' => 'سحب — دفع من الصندوق',
            'created_at' => '2026-09-01 10:00:00',
            'updated_at' => '2026-09-01 10:00:00',
        ]);

        $result = app(LegacyCashAuditService::class)->audit();

        $this->assertSame(80.0, $result['rows'][0]['amount']);
        $this->assertSame(LegacyCashAuditService::UNLINKED_CASH, $result['rows'][0]['classification']);
    }

    public function test_timestamp_window_selects_only_the_near_source_on_the_same_day(): void
    {
        $this->boxLog(1, 100, 'add', 'قبض — بيع فوري', null, '2026-09-01 10:02:00');
        $this->instantSale(10, 100, '2026-09-01 10:00:00');
        $this->instantSale(20, 100, '2026-09-01 15:00:00');

        $row = $this->row(app(LegacyCashAuditService::class)->audit(), 1);

        $this->assertSame(LegacyCashAuditService::LINKED_NOT_ACCOUNTED, $row['classification']);
        $this->assertSame('instant_sale', $row['matched_source_type']);
        $this->assertSame(10, $row['matched_source_id']);
    }

    public function test_same_day_without_a_reliable_near_timestamp_is_ambiguous(): void
    {
        $this->boxLog(1, 100, 'add', 'قبض — بيع فوري', null, '2026-09-01');
        $this->instantSale(10, 100, '2026-09-01 10:00:00');

        $row = $this->row(app(LegacyCashAuditService::class)->audit(), 1);

        $this->assertSame(LegacyCashAuditService::AMBIGUOUS, $row['classification']);
        $this->assertStringContainsString('اليوم نفسه', $row['reason']);
    }

    public function test_same_day_source_outside_the_window_is_only_weak_evidence(): void
    {
        $this->boxLog(1, 100, 'add', 'قبض — بيع فوري', null, '2026-09-01 10:00:00');
        $this->instantSale(10, 100, '2026-09-01 15:00:00');

        $row = $this->row(app(LegacyCashAuditService::class)->audit(), 1);

        $this->assertSame(LegacyCashAuditService::AMBIGUOUS, $row['classification']);
        $this->assertStringContainsString('اليوم نفسه', $row['reason']);
    }

    public function test_same_day_journal_outside_the_window_is_not_duplicate_risk(): void
    {
        $this->boxLog(1, -100, 'minus', 'سحب — دفع من الصندوق', null, '2026-09-01 10:00:00');
        $this->cashJournal(1, 'expense', 99, 0, 100, createdAt: '2026-09-01 15:00:00');

        $row = $this->row(app(LegacyCashAuditService::class)->audit(), 1);

        $this->assertSame(LegacyCashAuditService::AMBIGUOUS, $row['classification']);
        $this->assertNotSame(LegacyCashAuditService::DUPLICATE_ACCOUNTING_RISK, $row['classification']);
    }

    public function test_two_sources_inside_five_minute_window_are_ambiguous(): void
    {
        $this->boxLog(1, 100, 'add', 'قبض — بيع فوري', null, '2026-09-01 10:02:00');
        $this->instantSale(10, 100, '2026-09-01 10:00:00');
        $this->instantSale(20, 100, '2026-09-01 10:04:00');

        $row = $this->row(app(LegacyCashAuditService::class)->audit(), 1);

        $this->assertSame(LegacyCashAuditService::AMBIGUOUS, $row['classification']);
        $this->assertStringContainsString('أكثر من مصدر', $row['reason']);
    }

    public function test_direct_foreign_key_wins_even_when_timestamps_are_far_apart(): void
    {
        $this->boxLog(1, -100, 'minus', 'سحب — دفع من الصندوق', null, '2026-09-01 10:00:00');
        DB::table('purchase_payments')->insert([
            'id' => 50,
            'box_id' => 1,
            'box_log_id' => 1,
            'amount' => 100,
            'paid_at' => '2026-09-01',
            'created_at' => '2026-09-01 18:00:00',
            'updated_at' => '2026-09-01 18:00:00',
        ]);
        $this->cashJournal(1, 'purchase_payment', 50, 0, 100, createdAt: '2026-09-01 18:00:00');

        $row = $this->row(app(LegacyCashAuditService::class)->audit(), 1);

        $this->assertSame(LegacyCashAuditService::LINKED_AND_ACCOUNTED, $row['classification']);
        $this->assertSame('purchase_payment', $row['matched_source_type']);
        $this->assertSame(50, $row['matched_source_id']);
    }

    public function test_reversed_original_journal_is_reported_as_linked_but_reversed(): void
    {
        $this->boxLog(1, 100, 'add', 'تم اضافة رصيد للصندوق', 'owner_contribution');
        $this->cashJournal(1, 'box_adjustment', 1, 100, 0, status: 'reversed');

        $result = app(LegacyCashAuditService::class)->audit();

        $this->assertSame(LegacyCashAuditService::LINKED_BUT_REVERSED, $this->row($result, 1)['classification']);
        $this->assertSame(1, $result['summary']['linked_but_reversed']);
    }

    public function test_posted_reversal_makes_the_original_non_active(): void
    {
        $this->boxLog(1, 100, 'add', 'تم اضافة رصيد للصندوق', 'owner_contribution');
        $this->cashJournal(1, 'box_adjustment', 1, 100, 0);
        $this->cashJournal(2, 'box_adjustment', 1, 0, 100, reversesEntryId: 1, createdAt: '2026-09-02 10:00:00');

        $row = $this->row(app(LegacyCashAuditService::class)->audit(), 1);

        $this->assertSame(LegacyCashAuditService::LINKED_BUT_REVERSED, $row['classification']);
        $this->assertSame(1, $row['journal_entry_id']);
    }

    public function test_active_posted_original_without_reversal_is_accounted(): void
    {
        $this->boxLog(1, 100, 'add', 'تم اضافة رصيد للصندوق', 'owner_contribution');
        $this->cashJournal(1, 'box_adjustment', 1, 100, 0);

        $this->assertSame(
            LegacyCashAuditService::LINKED_AND_ACCOUNTED,
            $this->row(app(LegacyCashAuditService::class)->audit(), 1)['classification']
        );
    }

    public function test_archived_and_deleted_debt_sources_are_only_inactive_evidence(): void
    {
        $this->boxLog(1, 90, 'add', 'دفتر الديون - أخذت من شخص', null, '2026-09-01 10:01:00');
        $this->boxLog(2, 80, 'add', 'دفتر الديون - أخذت من شخص', null, '2026-09-01 11:01:00');
        $this->debtTransaction(10, 90, '2026-09-01 10:00:00', archivedAt: '2026-09-02 00:00:00');
        $this->debtTransaction(20, 80, '2026-09-01 11:00:00', deletedAt: '2026-09-02 00:00:00');

        $result = app(LegacyCashAuditService::class)->audit();

        $this->assertSame(LegacyCashAuditService::AMBIGUOUS, $this->row($result, 1)['classification']);
        $this->assertStringContainsString('مؤرشف', $this->row($result, 1)['reason']);
        $this->assertSame(LegacyCashAuditService::AMBIGUOUS, $this->row($result, 2)['classification']);
        $this->assertStringContainsString('محذوف', $this->row($result, 2)['reason']);
    }

    public function test_cancelled_instant_sale_is_not_treated_as_an_active_source(): void
    {
        $this->boxLog(1, 100, 'add', 'قبض — بيع فوري #10', null, '2026-09-01 10:01:00');
        $this->instantSale(10, 100, '2026-09-01 10:00:00', 'cancelled', '2026-09-01 12:00:00');

        $row = $this->row(app(LegacyCashAuditService::class)->audit(), 1);

        $this->assertSame(LegacyCashAuditService::AMBIGUOUS, $row['classification']);
        $this->assertStringContainsString('ملغي', $row['reason']);
    }

    public function test_negative_maintenance_payment_reverses_direction_to_cash_out(): void
    {
        $this->boxLog(1, -100, 'minus', 'سحب — عكس دفعة صيانة', null, '2026-09-01 10:01:00');
        DB::table('maintenance_payments')->insert([
            'id' => 10,
            'box_id' => 1,
            'amount' => -100,
            'method' => 'cancellation_reversal',
            'created_at' => '2026-09-01 10:00:00',
            'updated_at' => '2026-09-01 10:00:00',
        ]);
        $this->cashJournal(1, 'maintenance_payment', 10, 0, 100);
        $before = $this->databaseSnapshot();

        $result = app(LegacyCashAuditService::class)->audit();

        $this->assertSame(LegacyCashAuditService::LINKED_AND_ACCOUNTED, $this->row($result, 1)['classification']);
        $this->assertSame(10, $this->row($result, 1)['matched_source_id']);
        $this->assertSame($before, $this->databaseSnapshot());
    }

    public function test_negative_sales_order_settlement_reverses_direction_to_cash_out(): void
    {
        $this->boxLog(1, -100, 'minus', 'سحب — عكس تحصيل طلبية', null, '2026-09-01 10:01:00');
        DB::table('sales_order_settlements')->insert([
            'id' => 20,
            'box_id' => 1,
            'cash_amount' => -100,
            'source' => 'cancellation_reversal',
            'created_at' => '2026-09-01 10:00:00',
            'updated_at' => '2026-09-01 10:00:00',
        ]);
        $this->cashJournal(1, 'sales_order_settlement', 20, 0, 100);
        $before = $this->databaseSnapshot();

        $result = app(LegacyCashAuditService::class)->audit();

        $this->assertSame(LegacyCashAuditService::LINKED_AND_ACCOUNTED, $this->row($result, 1)['classification']);
        $this->assertSame(20, $this->row($result, 1)['matched_source_id']);
        $this->assertSame($before, $this->databaseSnapshot());
    }

    public function test_positive_maintenance_payment_keeps_cash_in_direction(): void
    {
        $this->boxLog(1, 100, 'add', 'قبض دفعة صيانة', null, '2026-09-01 10:01:00');
        DB::table('maintenance_payments')->insert([
            'id' => 10,
            'box_id' => 1,
            'amount' => 100,
            'method' => 'cash',
            'created_at' => '2026-09-01 10:00:00',
            'updated_at' => '2026-09-01 10:00:00',
        ]);
        $this->cashJournal(1, 'maintenance_payment', 10, 100, 0);

        $this->assertSame(
            LegacyCashAuditService::LINKED_AND_ACCOUNTED,
            $this->row(app(LegacyCashAuditService::class)->audit(), 1)['classification']
        );
    }

    public function test_positive_sales_order_settlement_keeps_cash_in_direction(): void
    {
        $this->boxLog(1, 100, 'add', 'قبض تحصيل طلبية', null, '2026-09-01 10:01:00');
        DB::table('sales_order_settlements')->insert([
            'id' => 20,
            'box_id' => 1,
            'cash_amount' => 100,
            'source' => 'carrier',
            'created_at' => '2026-09-01 10:00:00',
            'updated_at' => '2026-09-01 10:00:00',
        ]);
        $this->cashJournal(1, 'sales_order_settlement', 20, 100, 0);

        $this->assertSame(
            LegacyCashAuditService::LINKED_AND_ACCOUNTED,
            $this->row(app(LegacyCashAuditService::class)->audit(), 1)['classification']
        );
    }

    public function test_description_source_id_does_not_confirm_mismatched_amount(): void
    {
        $this->boxLog(1, 100, 'add', 'قبض — بيع فوري #123', null, '2026-09-01 10:01:00');
        $this->instantSale(123, 120, '2026-09-01 10:00:00');

        $row = $this->row(app(LegacyCashAuditService::class)->audit(), 1);

        $this->assertSame(LegacyCashAuditService::AMBIGUOUS, $row['classification']);
        $this->assertStringContainsString('لا يطابقه', $row['reason']);
    }

    private function boxLog(
        int $id,
        float $value,
        ?string $type,
        string $description,
        ?string $reasonCode = null,
        string $createdAt = '2026-09-01 10:00:00',
    ): void {
        DB::table('box_logs')->insert([
            'id' => $id,
            'box_id' => 1,
            'value' => $value,
            'transfered_balance' => abs($value),
            'type' => $type,
            'description' => $description,
            'reason_code' => $reasonCode,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function instantSale(
        int $id,
        float $amount,
        string $createdAt,
        ?string $status = null,
        ?string $cancelledAt = null,
    ): void {
        DB::table('instant_sales')->insert([
            'id' => $id,
            'payment_box_id' => 1,
            'payment_box_value' => $amount,
            'status' => $status,
            'cancelled_at' => $cancelledAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function debtTransaction(
        int $id,
        float $amount,
        string $createdAt,
        ?string $archivedAt = null,
        ?string $deletedAt = null,
    ): void {
        DB::table('debt_transactions')->insert([
            'id' => $id,
            'box_id' => 1,
            'amount' => $amount,
            'type' => 'taken',
            'transaction_date' => '2026-09-01',
            'source' => 'manual',
            'source_id' => null,
            'archived_at' => $archivedAt,
            'deleted_at' => $deletedAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function cashJournal(
        int $id,
        string $sourceType,
        int $sourceId,
        float $debit,
        float $credit,
        string $status = 'posted',
        ?int $reversesEntryId = null,
        string $createdAt = '2026-09-01 10:00:00',
    ): void {
        DB::table('accounting_journal_entries')->insert([
            'id' => $id,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'entry_date' => '2026-09-01',
            'currency' => 'شيكل',
            'status' => $status,
            'reverses_entry_id' => $reversesEntryId,
            'posted_at' => $createdAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        DB::table('accounting_journal_lines')->insert([
            'journal_entry_id' => $id,
            'account_id' => 1,
            'box_id' => 1,
            'debit' => $debit,
            'credit' => $credit,
        ]);
    }

    private function classification(array $result, int $boxLogId): string
    {
        return collect($result['rows'])->firstWhere('box_log_id', $boxLogId)['classification'];
    }

    private function row(array $result, int $boxLogId): array
    {
        return collect($result['rows'])->firstWhere('box_log_id', $boxLogId);
    }

    private function databaseSnapshot(): array
    {
        return collect([
            'boxes',
            'box_logs',
            'instant_sales',
            'purchase_payments',
            'maintenance_payments',
            'sales_order_settlements',
            'debt_transactions',
            'accounting_accounts',
            'accounting_journal_entries',
            'accounting_journal_lines',
        ])->mapWithKeys(fn (string $table) => [
            $table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        ])->all();
    }

    private function tableCounts(): array
    {
        return [
            'box_logs' => DB::table('box_logs')->count(),
            'instant_sales' => DB::table('instant_sales')->count(),
            'purchase_payments' => DB::table('purchase_payments')->count(),
            'maintenance_payments' => DB::table('maintenance_payments')->count(),
            'sales_order_settlements' => DB::table('sales_order_settlements')->count(),
            'debt_transactions' => DB::table('debt_transactions')->count(),
            'journal_entries' => DB::table('accounting_journal_entries')->count(),
            'journal_lines' => DB::table('accounting_journal_lines')->count(),
        ];
    }
}
