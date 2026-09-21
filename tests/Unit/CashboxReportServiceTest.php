<?php

namespace Tests\Unit;

use App\Http\Controllers\API\Reports;
use App\Models\Box;
use App\Models\BoxLog;
use App\Models\SalesDailyClosingRequest;
use App\Models\SalesDailySession;
use App\Services\CashboxReportService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CashboxReportServiceTest extends TestCase
{
    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = (string) config('database.default');
        config([
            'database.default' => 'cashbox_report_test',
            'database.connections.cashbox_report_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('cashbox_report_test');
        DB::setDefaultConnection('cashbox_report_test');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('boxes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('type')->nullable();
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->decimal('total', 14, 2)->default(0);
            $table->boolean('is_shown')->default(true);
            $table->string('currency')->nullable();
            $table->timestamps();
        });
        Schema::create('box_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('from_box_id')->nullable();
            $table->unsignedBigInteger('to_box_id')->nullable();
            $table->text('description')->nullable();
            $table->text('note')->nullable();
            $table->decimal('value', 14, 2)->default(0);
            $table->unsignedBigInteger('box_id')->nullable();
            $table->string('type')->nullable();
            $table->timestamps();
        });
        Schema::create('sales_daily_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->string('session_type')->default('instant_sales');
            $table->date('business_date');
            $table->string('status');
            $table->json('opening_balances')->nullable();
            $table->json('sales_orders_opening_balances')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('opened_by_user_id')->nullable();
            $table->unsignedBigInteger('closed_by_user_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('sales_daily_closing_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('requested_by_user_id')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->string('status');
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->unsignedInteger('instant_sales_count')->default(0);
            $table->unsignedInteger('profit_sales_count')->default(0);
            $table->json('cash_counts')->nullable();
            $table->json('sales_orders_cash_counts')->nullable();
            $table->text('late_close_reason')->nullable();
            $table->json('transfers')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        DB::purge('cashbox_report_test');
        DB::setDefaultConnection($this->originalConnection);
        parent::tearDown();
    }

    public function test_statement_uses_a_permanent_box_and_builds_a_running_balance(): void
    {
        $main = Box::create([
            'name' => 'الصندوق الرئيسي',
            'total' => 120,
            'is_shown' => true,
            'currency' => 'شيكل',
        ]);
        Box::create([
            'name' => 'صندوق مبيعات يومي',
            'type' => 'daily_sales',
            'total' => 500,
            'is_shown' => false,
            'currency' => 'شيكل',
        ]);
        BoxLog::create([
            'box_id' => $main->id,
            'type' => 'add',
            'value' => 50,
            'description' => 'قبض',
            'created_at' => '2026-09-10 09:00:00',
            'updated_at' => '2026-09-10 09:00:00',
        ]);
        BoxLog::create([
            'box_id' => $main->id,
            'type' => 'minus',
            'value' => 30,
            'description' => 'صرف',
            'created_at' => '2026-09-11 09:00:00',
            'updated_at' => '2026-09-11 09:00:00',
        ]);

        $service = app(CashboxReportService::class);
        $this->assertCount(1, $service->permanentBoxes());

        $report = $service->statement(
            $main->id,
            Carbon::parse('2026-09-01'),
            Carbon::parse('2026-09-30'),
        );
        $rows = $report['rows']->values();

        $this->assertCount(2, $rows);
        $this->assertSame(50.0, $rows[0]['incoming']);
        $this->assertSame(150.0, $rows[0]['balance']);
        $this->assertSame(30.0, $rows[1]['outgoing']);
        $this->assertSame(120.0, $rows[1]['balance']);
        $this->assertStringContainsString('الصندوق الرئيسي', $report['title']);
    }

    public function test_daily_session_report_uses_the_historical_closing_snapshot(): void
    {
        DB::table('users')->insert([
            'id' => 1,
            'name' => 'موظف المبيعات',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $daily = Box::create([
            'name' => 'صندوق مبيعات يومي',
            'type' => 'daily_sales',
            'user_id' => 1,
            'total' => 10,
            'is_shown' => false,
            'currency' => 'شيكل',
        ]);
        $main = Box::create([
            'name' => 'الصندوق الرئيسي',
            'total' => 90,
            'is_shown' => true,
            'currency' => 'شيكل',
        ]);
        $session = SalesDailySession::create([
            'user_id' => 1,
            'session_type' => 'instant_sales',
            'business_date' => '2026-09-10',
            'status' => 'closed',
            'opening_balances' => ['شيكل' => 10],
            'opened_at' => '2026-09-10 08:00:00',
            'closed_at' => '2026-09-10 20:00:00',
        ]);
        SalesDailyClosingRequest::create([
            'session_id' => $session->id,
            'status' => 'approved',
            'cash_counts' => [[
                'currency' => 'شيكل',
                'daily_box_id' => $daily->id,
                'opening_float' => 10,
                'sales_collected' => 100,
                'system_balance' => 110,
                'physical_count' => 100,
                'variance' => -10,
                'float_to_keep' => 10,
                'amount_to_transfer' => 90,
            ]],
            'transfers' => [[
                'currency' => 'شيكل',
                'from_box_id' => $daily->id,
                'to_box_id' => $main->id,
                'amount' => 90,
            ]],
        ]);

        $report = app(CashboxReportService::class)->dailySessions(
            Carbon::parse('2026-09-01'),
            Carbon::parse('2026-09-30'),
        );
        $row = $report['rows']->first();

        $this->assertSame('مبيعات', $row['session_type']);
        $this->assertSame('موظف المبيعات', $row['employee']);
        $this->assertSame(110.0, $row['expected']);
        $this->assertSame(100.0, $row['physical']);
        $this->assertSame(-10.0, $row['variance']);
        $this->assertSame(90.0, $row['transferred']);
        $this->assertSame('الصندوق الرئيسي', $row['transfer_to']);
        $this->assertSame('مغلقة', $row['status']);
    }

    public function test_report_api_rejects_a_daily_box_as_a_permanent_statement_box(): void
    {
        $daily = Box::create([
            'name' => 'صندوق مبيعات يومي',
            'type' => 'daily_sales',
            'total' => 0,
            'is_shown' => false,
            'currency' => 'شيكل',
        ]);
        $request = Request::create('/api/admin/reports/data', 'GET', [
            'type' => 'boxes',
            'period' => 'month',
            'box_id' => $daily->id,
        ]);

        $response = app(Reports::class)->reportData($request);
        $payload = $response->getData(true);

        $this->assertSame('error', $payload['status']);
        $this->assertArrayHasKey('box_id', $payload['errors']);
    }
}
