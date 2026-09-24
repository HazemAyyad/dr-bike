<?php

namespace Tests\Unit;

use App\Http\Controllers\API\InstantSales;
use App\Http\Controllers\API\ProfitSales;
use App\Models\SalesOrder;
use App\Services\SalesOrderService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class SalesCustomerSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        $pdo = DB::connection()->getPdo();
        $pdo->sqliteCreateFunction('CONCAT', fn (...$values) => implode('', $values));
        $pdo->sqliteCreateFunction(
            'LPAD',
            fn ($value, $length, $padding) => str_pad((string) $value, (int) $length, (string) $padding, STR_PAD_LEFT)
        );

        $this->createPartyTables();
        $this->createSalesOrdersTable();
        $this->createProfitSalesTable();
        $this->createInstantSalesTables();
    }

    public function test_sales_order_search_matches_linked_customer_or_seller_name_and_phone(): void
    {
        DB::table('customers')->insert([
            'id' => 10,
            'name' => 'أحمد الزبون',
            'phone' => '0591111111',
            'sub_phone' => '0561111111',
        ]);
        DB::table('sellers')->insert([
            'id' => 20,
            'name' => 'محمود المورد',
            'phone' => '0592222222',
            'sub_phone' => '0562222222',
        ]);
        DB::table('sales_orders')->insert([
            [
                'id' => 1,
                'serial_number' => 'SO-1',
                'customer_id' => 10,
                'partner_type' => 'customer',
                'partner_id' => 10,
                'customer_name' => 'اسم قديم',
                'status' => 'delivered',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'serial_number' => 'SO-2',
                'customer_id' => null,
                'partner_type' => 'seller',
                'partner_id' => 20,
                'customer_name' => 'اسم قديم',
                'status' => 'delivered',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->assertSame([1], $this->filteredSalesOrderIds('أحمد'));
        $this->assertSame([1], $this->filteredSalesOrderIds('0561111111'));
        $this->assertSame([2], $this->filteredSalesOrderIds('محمود'));
        $this->assertSame([2], $this->filteredSalesOrderIds('0592222222'));
    }

    public function test_profit_sales_search_returns_linked_customer_phone(): void
    {
        DB::table('customers')->insert([
            'id' => 30,
            'name' => 'سامي الربحي',
            'phone' => '0593333333',
            'sub_phone' => '0563333333',
        ]);
        DB::table('profit_sales')->insert([
            'id' => 3,
            'total_cost' => 100,
            'notes' => 'ربح',
            'buyer_type' => 'customer',
            'customer_id' => 30,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = (new ProfitSales)->getProfitSales(Request::create(
            '/api/all/profit/sales',
            'GET',
            ['search' => '0563333333']
        ));
        $payload = $response->getData(true);

        $this->assertSame('success', $payload['status']);
        $this->assertCount(1, $payload['profit_sales']);
        $this->assertSame('سامي الربحي', $payload['profit_sales'][0]['buyer_name']);
        $this->assertSame('0593333333', $payload['profit_sales'][0]['buyer_phone']);
    }

    public function test_instant_sales_search_matches_linked_customer_name_and_phone(): void
    {
        DB::table('customers')->insert([
            'id' => 40,
            'name' => 'ليلى الفوري',
            'phone' => '0594444444',
            'sub_phone' => '0564444444',
        ]);
        DB::table('instant_sales')->insert([
            'id' => 4,
            'total_cost' => 75,
            'cost' => 75,
            'quantity' => 1,
            'buyer_type' => 'customer',
            'buyer_id' => 40,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['ليلى', '0564444444'] as $search) {
            $response = (new InstantSales)->getInstantSales(Request::create(
                '/api/instant/sales',
                'GET',
                ['search' => $search]
            ));
            $payload = $response->getData(true);

            $this->assertSame('success', $payload['status']);
            $this->assertCount(1, $payload['instant_sales']);
            $this->assertSame('ليلى الفوري', $payload['instant_sales'][0]['buyer_name']);
            $this->assertSame('0594444444', $payload['instant_sales'][0]['buyer_phone']);
        }
    }

    /** @return list<int> */
    private function filteredSalesOrderIds(string $search): array
    {
        $service = (new ReflectionClass(SalesOrderService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(SalesOrderService::class, 'applyListFilters');
        $query = SalesOrder::query();
        $method->invoke($service, $query, ['search' => $search], false);

        return $query->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function createPartyTables(): void
    {
        foreach (['customers', 'sellers'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('phone')->nullable();
                $table->string('sub_phone')->nullable();
                $table->timestamps();
            });
        }
    }

    private function createSalesOrdersTable(): void
    {
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->string('serial_number')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('partner_type')->nullable();
            $table->unsignedBigInteger('partner_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('status');
            $table->timestamp('hidden_until')->nullable();
            $table->timestamps();
        });
    }

    private function createProfitSalesTable(): void
    {
        Schema::create('profit_sales', function (Blueprint $table) {
            $table->id();
            $table->decimal('total_cost', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->string('image_path')->nullable();
            $table->string('video_path')->nullable();
            $table->string('buyer_type')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('buyer_name')->nullable();
            $table->unsignedBigInteger('payment_box_id')->nullable();
            $table->string('payment_box_name')->nullable();
            $table->decimal('payment_box_value', 12, 2)->nullable();
            $table->string('status')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('sales_daily_session_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('boxes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
    }

    private function createInstantSalesTables(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('nameAr')->nullable();
            $table->softDeletes();
        });
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
        });
        Schema::create('offer_packages', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
        });
        Schema::create('instant_sales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('size_id')->nullable();
            $table->unsignedBigInteger('size_color_id')->nullable();
            $table->unsignedBigInteger('offer_package_id')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->decimal('total_cost', 12, 2)->default(0);
            $table->decimal('cost', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->integer('quantity')->default(1);
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('type')->nullable();
            $table->string('sale_kind')->nullable();
            $table->string('buyer_type')->nullable();
            $table->unsignedBigInteger('buyer_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('buyer_name')->nullable();
            $table->string('buyer_phone')->nullable();
            $table->string('buyer_address')->nullable();
            $table->unsignedBigInteger('payment_box_id')->nullable();
            $table->string('payment_box_name')->nullable();
            $table->decimal('payment_box_value', 12, 2)->nullable();
            $table->string('status')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->boolean('stock_restored')->default(false);
            $table->unsignedBigInteger('maintenance_id')->nullable();
            $table->string('serial_number')->nullable();
            $table->timestamps();
        });
    }
}
