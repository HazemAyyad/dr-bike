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

        $before = $this->tableCounts();
        $result = app(LegacyCashAuditService::class)->audit();
        $after = $this->tableCounts();

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

    private function boxLog(int $id, float $value, string $type, string $description, ?string $reasonCode = null): void
    {
        DB::table('box_logs')->insert([
            'id' => $id,
            'box_id' => 1,
            'value' => $value,
            'transfered_balance' => abs($value),
            'type' => $type,
            'description' => $description,
            'reason_code' => $reasonCode,
            'created_at' => '2026-09-01 10:00:00',
            'updated_at' => '2026-09-01 10:00:00',
        ]);
    }

    private function cashJournal(int $id, string $sourceType, int $sourceId, float $debit, float $credit): void
    {
        DB::table('accounting_journal_entries')->insert([
            'id' => $id,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'entry_date' => '2026-09-01',
            'currency' => 'شيكل',
            'status' => 'posted',
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

    private function tableCounts(): array
    {
        return [
            'box_logs' => DB::table('box_logs')->count(),
            'instant_sales' => DB::table('instant_sales')->count(),
            'journal_entries' => DB::table('accounting_journal_entries')->count(),
            'journal_lines' => DB::table('accounting_journal_lines')->count(),
        ];
    }
}
