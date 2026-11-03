<?php

namespace Tests\Unit;

use App\Http\Controllers\API\Boxes;
use App\Http\Controllers\API\BoxLogs;
use App\Models\Box;
use App\Models\BoxLog;
use App\Services\BoxAccessService;
use App\Services\BoxLogBalanceService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BoxLogBalanceServiceTest extends TestCase
{
    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = (string) config('database.default');
        config(['database.default' => 'box_balance_test', 'database.connections.box_balance_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::purge('box_balance_test');
        DB::setDefaultConnection('box_balance_test');
        Schema::create('boxes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('Main');
            $table->string('type')->nullable();
            $table->string('currency')->default('شيكل');
            $table->boolean('is_shown')->default(true);
            $table->decimal('total', 14, 4);
            $table->timestamps();
        });
        Schema::create('box_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('box_id')->nullable();
            $table->unsignedBigInteger('from_box_id')->nullable();
            $table->unsignedBigInteger('to_box_id')->nullable();
            $table->string('type')->nullable();
            $table->decimal('value', 14, 4)->nullable();
            $table->decimal('transfered_balance', 14, 4)->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        DB::disconnect('box_balance_test');
        DB::setDefaultConnection($this->originalConnection);
        parent::tearDown();
    }

    private function box(int $id, float $total): void
    {
        DB::table('boxes')->insert(['id' => $id, 'total' => $total]);
    }

    private function log(array $attributes): BoxLog
    {
        return BoxLog::forceCreate(array_merge([
            'box_id' => 1, 'type' => 'add', 'value' => 0,
            'created_at' => '2026-11-03 10:00:00', 'updated_at' => '2026-11-03 10:00:00',
        ], $attributes));
    }

    public function test_balances_use_full_history_even_when_only_one_filtered_row_is_requested(): void
    {
        $this->box(1, 155);
        $first = $this->log(['value' => 100]); // Existing opening balance: 80.
        $middle = $this->log(['type' => 'minus', 'value' => 35]);
        $this->log(['value' => 10]);
        app(BoxLogBalanceService::class)->attach(collect([$middle]));
        $this->assertSame(180.0, $middle->box_balance_before);
        $this->assertSame(145.0, $middle->box_balance_after);
        $this->assertNull($first->box_balance_before);
        $this->assertEquals(155, Box::find(1)->total);
        $this->assertNull($middle->fresh()->box_balance_before);
    }

    public function test_transfer_has_both_balances_and_uses_the_selected_box_perspective(): void
    {
        $this->box(1, 70);
        $this->box(2, 50);
        $transfer = $this->log(['box_id' => null, 'from_box_id' => 1, 'to_box_id' => 2, 'type' => 'transfer', 'value' => 30]);
        app(BoxLogBalanceService::class)->attach(collect([$transfer]), null, 2);
        $this->assertSame(100.0, $transfer->from_box_balance_before);
        $this->assertSame(70.0, $transfer->from_box_balance_after);
        $this->assertSame(20.0, $transfer->to_box_balance_before);
        $this->assertSame(50.0, $transfer->to_box_balance_after);
        $this->assertSame(20.0, $transfer->box_balance_before);
        $this->assertSame(50.0, $transfer->box_balance_after);
    }

    public function test_balances_of_a_hidden_transfer_counterparty_are_not_returned(): void
    {
        $this->box(1, 70);
        $this->box(2, 50);
        $transfer = $this->log(['box_id' => null, 'from_box_id' => 1, 'to_box_id' => 2, 'type' => 'transfer', 'value' => 30]);
        app(BoxLogBalanceService::class)->attach(collect([$transfer]), [1], 1);
        $this->assertSame(100.0, $transfer->box_balance_before);
        $this->assertNull($transfer->to_box_balance_before);
        $this->assertNull($transfer->to_box_balance_after);
    }

    public function test_legacy_transfer_without_type_or_value_has_balances_and_a_display_amount(): void
    {
        $this->box(1, 70);
        $this->box(2, 50);
        $transfer = $this->log(['box_id' => null, 'from_box_id' => 1, 'to_box_id' => 2, 'type' => null, 'value' => null, 'transfered_balance' => 30]);
        app(BoxLogBalanceService::class)->attach(collect([$transfer]));
        $this->assertSame(100.0, $transfer->from_box_balance_before);
        $this->assertSame(20.0, $transfer->to_box_balance_before);
        $this->assertSame('transfer', $transfer->type);
        $this->assertSame(30.0, $transfer->value);
    }

    public function test_negative_amounts_and_legacy_values_are_supported(): void
    {
        $this->box(1, -10.25);
        $legacy = $this->log(['value' => null, 'transfered_balance' => 20.25]);
        $negative = $this->log(['type' => 'expense', 'value' => -30.5]);
        app(BoxLogBalanceService::class)->attach(collect([$negative, $legacy]));
        $this->assertSame(0.0, $legacy->box_balance_before);
        $this->assertSame(20.25, $legacy->box_balance_after);
        $this->assertSame(20.25, $negative->box_balance_before);
        $this->assertSame(-10.25, $negative->box_balance_after);
    }

    public function test_maintenance_cash_is_included_but_non_cash_payments_are_ignored(): void
    {
        Schema::create('maintenance_daily_box_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('box_id');
            $table->decimal('amount', 14, 2);
            $table->boolean('affects_cash_balance');
            $table->timestamps();
        });
        $this->box(1, 110);
        $early = $this->log(['value' => 100]);
        DB::table('maintenance_daily_box_logs')->insert([
            ['box_id' => 1, 'amount' => 30, 'affects_cash_balance' => true, 'created_at' => '2026-11-03 11:00:00'],
            ['box_id' => 1, 'amount' => 500, 'affects_cash_balance' => false, 'created_at' => '2026-11-03 11:01:00'],
        ]);
        $late = $this->log(['type' => 'minus', 'value' => -20, 'created_at' => '2026-11-03 12:00:00']);
        app(BoxLogBalanceService::class)->attach(collect([$late, $early]));
        $this->assertSame(0.0, $early->box_balance_before);
        $this->assertSame(100.0, $early->box_balance_after);
        $this->assertSame(130.0, $late->box_balance_before);
        $this->assertSame(110.0, $late->box_balance_after);
    }

    public function test_box_details_response_includes_balances_from_the_requested_box(): void
    {
        $this->box(1, 70);
        $this->box(2, 50);
        $this->log(['box_id' => null, 'from_box_id' => 1, 'to_box_id' => 2, 'type' => 'transfer', 'value' => 30]);
        $access = \Mockery::mock(BoxAccessService::class);
        $access->shouldReceive('canAccess')->once()->with(null, 2)->andReturn(true);
        $response = (new Boxes($access))->showBox(Request::create('/show/box', 'POST', ['box_id' => 2]));
        $data = $response->getData(true);
        $this->assertSame('success', $data['status']);
        $row = $data['box details']['box_logs'][0];
        $this->assertEquals(20, $row['box_balance_before']);
        $this->assertEquals(50, $row['box_balance_after']);
        $this->assertArrayNotHasKey('from_box_balance_before', $row);
    }

    public function test_general_movements_response_includes_balances(): void
    {
        Schema::create('maintenance_daily_box_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('box_id');
            $table->timestamps();
        });
        $this->box(1, 100);
        $this->log(['value' => 100]);
        $access = \Mockery::mock(BoxAccessService::class);
        $access->shouldReceive('visibleBoxIds')->once()->andReturn([1]);
        $data = (new BoxLogs($access))->allBoxLogs()->getData(true);
        $this->assertSame('success', $data['status']);
        $this->assertEquals(0, $data['box_logs'][0]['box_balance_before']);
        $this->assertEquals(100, $data['box_logs'][0]['box_balance_after']);
    }
}
