<?php

namespace Tests\Unit;

use App\Http\Controllers\API\Bills as BillsController;
use App\Models\Bill;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\DebtLedgerService;
use App\Services\InventoryCostingService;
use App\Services\PurchaseInvoicePurgeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class PurchaseInvoicePurgeServiceTest extends TestCase
{
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
            $table->string('password')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('nameAr')->nullable();
            $table->integer('stock')->default(0);
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->decimal('total', 14, 4)->default(0);
            $table->decimal('final_total', 14, 4)->default(0);
            $table->decimal('paid_amount', 14, 4)->default(0);
            $table->string('status')->default('unfinished');
            $table->string('workflow_status')->default('awaiting_receiving');
            $table->string('payment_status')->default('unpaid');
            $table->string('currency')->default('شيكل');
            $table->timestamps();
        });
        Schema::create('bill_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->constrained('bills')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->unsignedBigInteger('size_id')->nullable();
            $table->unsignedBigInteger('size_color_id')->nullable();
            $table->decimal('quantity', 14, 4)->default(0);
            $table->decimal('received_owned_quantity', 14, 4)->default(0);
            $table->decimal('price', 14, 4)->default(0);
            $table->timestamps();
        });
        Schema::create('purchase_receipts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bill_id');
            $table->timestamps();
        });
        Schema::create('purchase_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_receipt_id');
            $table->timestamps();
        });
        Schema::create('purchase_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bill_id')->nullable();
            $table->unsignedBigInteger('debt_transaction_id')->nullable();
            $table->timestamps();
        });
        Schema::create('purchase_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_payment_id');
            $table->unsignedBigInteger('bill_id');
            $table->timestamps();
        });
        Schema::create('returns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bill_id')->nullable();
            $table->string('status')->default('draft');
            $table->unsignedBigInteger('debt_transaction_id')->nullable();
            $table->timestamps();
        });
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('return_id');
            $table->unsignedBigInteger('bill_item_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->decimal('quantity', 14, 4)->default(0);
            $table->timestamps();
        });
        Schema::create('purchase_return_settlements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('return_id');
            $table->unsignedBigInteger('debt_transaction_id')->nullable();
            $table->timestamps();
        });
        foreach (['purchase_amanat_stocks', 'purchase_issue_resolutions', 'purchase_price_histories', 'purchase_activity_logs'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('bill_id')->nullable();
                $table->timestamps();
            });
        }
        Schema::create('purchase_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bill_id')->nullable();
            $table->string('attachable_type')->nullable();
            $table->unsignedBigInteger('attachable_id')->nullable();
            $table->string('disk')->default('public');
            $table->string('path');
            $table->timestamps();
        });
        Schema::create('debt_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('source')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
        Schema::create('purchase_invoice_purge_backups', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->unsignedBigInteger('bill_id');
            $table->string('bill_reference');
            $table->string('workflow_status')->nullable();
            $table->text('reason');
            $table->longText('payload');
            $table->text('result_summary')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function test_purge_controller_rejects_a_wrong_current_account_password(): void
    {
        $user = User::query()->create(['name' => 'Admin', 'type' => 'admin']);
        $user->forceFill(['password' => Hash::make('correct-password')])->save();
        $bill = Bill::query()->create([
            'total' => 5,
            'final_total' => 5,
            'paid_amount' => 0,
            'status' => 'unfinished',
            'workflow_status' => 'awaiting_receiving',
            'payment_status' => 'unpaid',
            'currency' => 'شيكل',
        ]);
        $request = Request::create('/api/purchase/purge', 'POST', [
            'bill_id' => $bill->id,
            'password' => 'wrong-password',
            'reason' => 'اختبار حماية الحذف',
        ]);
        $request->setUserResolver(fn () => $user);
        $purgeService = Mockery::mock(PurchaseInvoicePurgeService::class);
        $purgeService->shouldNotReceive('purge');

        $response = (new BillsController)->purgePurchaseInvoice($request, $purgeService);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('error', $response->getData(true)['status']);
        $this->assertSame('كلمة مرور الحساب غير صحيحة', $response->getData(true)['message']);
        $this->assertDatabaseHas('bills', ['id' => $bill->id]);
    }

    public function test_purge_backs_up_invoice_and_removes_its_net_received_stock(): void
    {
        $user = User::query()->create(['name' => 'Admin', 'type' => 'admin']);
        $product = Product::withoutEvents(fn () => Product::query()->create(['id' => 10, 'nameAr' => 'Battery', 'stock' => 10]));
        $bill = Bill::query()->create([
            'total' => 100,
            'final_total' => 100,
            'paid_amount' => 0,
            'status' => 'finished',
            'workflow_status' => 'finalized',
            'payment_status' => 'unpaid',
            'currency' => 'شيكل',
        ]);
        DB::table('bill_items')->insert([
            'bill_id' => $bill->id,
            'product_id' => $product->id,
            'quantity' => 10,
            'received_owned_quantity' => 10,
            'price' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $costing = Mockery::mock(InventoryCostingService::class);
        $costing->shouldReceive('consumeOwnedStock')->once()->with(
            Mockery::type(Product::class),
            10.0,
            ProductStockMovement::TYPE_PURCHASE_INVOICE_DELETE,
            'purchase_invoice_delete',
            Mockery::type('int'),
            null,
            null,
            (int) $user->id,
            Mockery::type('string'),
            Mockery::type('string'),
            true,
            [],
        )->andReturn([
            'method' => 'fifo',
            'total_cost' => 100.0,
            'unit_cost' => 10.0,
            'allocations' => [],
            'cost_complete' => true,
            'pending_quantity' => 0.0,
        ]);
        $service = new PurchaseInvoicePurgeService(
            $costing,
            Mockery::mock(DebtLedgerService::class),
            Mockery::mock(AccountingService::class),
        );

        $result = $service->purge($bill, $user, 'فاتورة مكررة');

        $this->assertNull(Bill::query()->find($bill->id));
        $this->assertDatabaseMissing('bill_items', ['bill_id' => $bill->id]);
        $this->assertDatabaseHas('purchase_invoice_purge_backups', [
            'bill_id' => $bill->id,
            'bill_reference' => 'PUR-'.$bill->id,
            'reason' => 'فاتورة مكررة',
        ]);
        $this->assertSame(10.0, $result['stock']['quantity_removed']);
    }
}
