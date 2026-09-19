<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\DebtLedgerService;
use App\Services\InventoryCostingService;
use App\Services\ProductStockService;
use App\Services\SalesOrderPurgeService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class SalesOrderPurgeServiceTest extends TestCase
{
    private SalesOrderPurgeService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->string('serial_number')->nullable();
            $table->string('status');
            $table->timestamps();
        });
        Schema::create('sales_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sales_order_id');
        });
        Schema::create('sales_order_media', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sales_order_id');
            $table->string('path')->nullable();
        });
        Schema::create('boxes', function (Blueprint $table) {
            $table->id();
            $table->string('currency')->nullable();
            $table->decimal('total', 14, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('box_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('box_id')->nullable();
            $table->text('description')->nullable();
            $table->text('note')->nullable();
            $table->decimal('value', 14, 2)->nullable();
            $table->decimal('transfered_balance', 14, 2)->default(0);
            $table->string('type')->nullable();
            $table->timestamps();
        });
        Schema::create('sales_order_settlements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sales_order_id');
            $table->unsignedBigInteger('box_id')->nullable();
            $table->decimal('amount', 14, 2);
            $table->decimal('cash_amount', 14, 2)->nullable();
            $table->timestamps();
        });
        Schema::create('sales_returns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sales_order_id')->nullable();
            $table->decimal('cash_refund_amount', 14, 2)->default(0);
            $table->decimal('credit_amount', 14, 2)->default(0);
            $table->unsignedBigInteger('debt_transaction_id')->nullable();
        });
        Schema::create('instant_sales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sales_order_id')->nullable();
        });
        Schema::create('sales_order_deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sales_order_id');
            $table->string('shiply_parcel_code')->nullable();
        });
        Schema::create('debt_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('source');
            $table->unsignedBigInteger('source_id');
            $table->timestamps();
        });
        Schema::create('sales_order_purge_backups', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->dateTime('cutoff_at');
            $table->unsignedBigInteger('max_order_id');
            $table->unsignedInteger('orders_count');
            $table->string('mode')->default('with_effects');
            $table->string('status')->default('available');
            $table->longText('payload');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('restored_by')->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();
        });
        Schema::create('document_serials', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->string('document_type');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['year', 'document_type']);
        });

        DB::table('users')->insert([
            'id' => 1,
            'name' => 'Admin',
            'type' => 'admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->service = new SalesOrderPurgeService(
            Mockery::mock(ProductStockService::class),
            Mockery::mock(InventoryCostingService::class),
            Mockery::mock(DebtLedgerService::class),
        );
    }

    public function test_preview_uses_current_order_box_logs_and_ignores_reused_old_id_logs(): void
    {
        DB::table('sales_orders')->insert([
            'id' => 4,
            'serial_number' => '0000044',
            'status' => 'delivered',
            'created_at' => '2026-09-01 10:00:00',
            'updated_at' => '2026-09-01 10:00:00',
        ]);
        DB::table('boxes')->insert([
            'id' => 1,
            'currency' => 'شيكل',
            'total' => 500,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('box_logs')->insert([
            [
                'box_id' => 1,
                'description' => 'قبض — طلبية 0000004',
                'note' => 'طلبية #4 — 0000004',
                'value' => 300,
                'type' => 'add',
                'created_at' => '2026-08-01 10:00:00',
                'updated_at' => '2026-08-01 10:00:00',
            ],
            [
                'box_id' => 1,
                'description' => 'قبض — طلبية 0000044',
                'note' => 'طلبية #4 — 0000044',
                'value' => 100,
                'type' => 'add',
                'created_at' => '2026-09-02 10:00:00',
                'updated_at' => '2026-09-02 10:00:00',
            ],
            [
                'box_id' => 1,
                'description' => 'قبض — طلبية 0000040',
                'note' => 'طلبية #40 — 0000040',
                'value' => 900,
                'type' => 'add',
                'created_at' => '2026-09-02 11:00:00',
                'updated_at' => '2026-09-02 11:00:00',
            ],
        ]);

        $preview = $this->service->preview(Carbon::parse('2026-09-30')->endOfDay());

        $this->assertSame(1, $preview['orders_count']);
        $this->assertSame(['delivered' => 1], $preview['status_counts']);
        $this->assertSame(100.0, $preview['net_cash_by_currency']['شيكل']);
        $this->assertTrue($preview['can_purge']);
    }

    public function test_purge_creates_restorable_backup_and_restores_order_and_cash(): void
    {
        DB::table('sales_orders')->insert([
            'id' => 7,
            'serial_number' => '0000047',
            'status' => 'delivered',
            'created_at' => '2026-09-01 10:00:00',
            'updated_at' => '2026-09-01 10:00:00',
        ]);
        DB::table('boxes')->insert([
            'id' => 1,
            'currency' => 'شيكل',
            'total' => 500,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('box_logs')->insert([
            'box_id' => 1,
            'description' => 'قبض — طلبية 0000047',
            'note' => 'طلبية #7 — 0000047',
            'value' => 125,
            'type' => 'add',
            'created_at' => '2026-09-02 10:00:00',
            'updated_at' => '2026-09-02 10:00:00',
        ]);

        $user = new User;
        $user->forceFill(['id' => 1, 'type' => 'admin']);
        $result = $this->service->purge(
            $user,
            Carbon::parse('2026-09-30')->endOfDay(),
            7,
        );

        $this->assertSame(1, $result['orders_count']);
        $this->assertDatabaseMissing('sales_orders', ['id' => 7]);
        $this->assertDatabaseMissing('box_logs', ['note' => 'طلبية #7 — 0000047']);
        $this->assertSame(375.0, (float) DB::table('boxes')->where('id', 1)->value('total'));
        $this->assertSame('available', $result['backup']['status']);
        $this->assertDatabaseHas('sales_order_purge_backups', [
            'id' => $result['backup']['id'],
            'orders_count' => 1,
            'status' => 'available',
        ]);

        $restored = $this->service->restoreBackup($user, (int) $result['backup']['id']);

        $this->assertSame('restored', $restored['status']);
        $this->assertDatabaseHas('sales_orders', ['id' => 7, 'serial_number' => '0000047']);
        $this->assertDatabaseHas('box_logs', ['note' => 'طلبية #7 — 0000047']);
        $this->assertSame(500.0, (float) DB::table('boxes')->where('id', 1)->value('total'));
    }

    public function test_orders_only_mode_preserves_financial_effects_and_resets_both_counters(): void
    {
        DB::table('sales_orders')->insert([
            'id' => 7,
            'serial_number' => '0000047',
            'status' => 'delivered',
            'created_at' => '2026-09-01 10:00:00',
            'updated_at' => '2026-09-01 10:00:00',
        ]);
        DB::table('document_serials')->insert([
            'year' => 0,
            'document_type' => 'SO',
            'last_number' => 47,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('boxes')->insert([
            'id' => 1,
            'currency' => 'شيكل',
            'total' => 500,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('box_logs')->insert([
            'box_id' => 1,
            'description' => 'قبض — طلبية 0000047',
            'note' => 'طلبية #7 — 0000047',
            'value' => 125,
            'type' => 'add',
            'created_at' => '2026-09-02 10:00:00',
            'updated_at' => '2026-09-02 10:00:00',
        ]);
        DB::table('debt_transactions')->insert([
            'customer_id' => 1,
            'source' => 'sales_order',
            'source_id' => 7,
            'created_at' => '2026-09-02 10:00:00',
            'updated_at' => '2026-09-02 10:00:00',
        ]);

        $user = new User;
        $user->forceFill(['id' => 1, 'type' => 'admin']);
        $result = $this->service->purge(
            $user,
            Carbon::parse('2026-09-30')->endOfDay(),
            7,
            SalesOrderPurgeService::MODE_ORDERS_ONLY_RESET,
        );

        $this->assertSame('orders_only_reset', $result['backup']['mode']);
        $this->assertDatabaseMissing('sales_orders', ['id' => 7]);
        $this->assertDatabaseHas('box_logs', ['note' => 'طلبية #7 — 0000047']);
        $this->assertDatabaseHas('debt_transactions', ['source' => 'sales_order', 'source_id' => 7]);
        $this->assertSame(500.0, (float) DB::table('boxes')->where('id', 1)->value('total'));
        $this->assertSame(0, (int) DB::table('document_serials')->where('document_type', 'SO')->value('last_number'));

        $newId = DB::table('sales_orders')->insertGetId([
            'serial_number' => '0000001',
            'status' => 'unconfirmed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertSame(1, $newId);

        $restored = $this->service->restoreBackup($user, (int) $result['backup']['id']);

        $this->assertSame('restored', $restored['status']);
        $this->assertDatabaseHas('sales_orders', ['id' => 7, 'serial_number' => '0000047']);
        $this->assertSame(1, DB::table('box_logs')->where('note', 'طلبية #7 — 0000047')->count());
        $this->assertSame(1, DB::table('debt_transactions')->where('source_id', 7)->count());
        $this->assertSame(500.0, (float) DB::table('boxes')->where('id', 1)->value('total'));
        $this->assertSame(47, (int) DB::table('document_serials')->where('document_type', 'SO')->value('last_number'));
    }
}
