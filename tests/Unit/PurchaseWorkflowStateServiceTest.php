<?php

namespace Tests\Unit;

use App\Models\Bill;
use App\Models\BillItem;
use App\Services\PurchaseWorkflowStateService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PurchaseWorkflowStateServiceTest extends TestCase
{
    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = (string) config('database.default');
        config()->set('database.connections.purchase_workflow_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        config()->set('database.default', 'purchase_workflow_test');
        DB::purge('purchase_workflow_test');
        DB::reconnect('purchase_workflow_test');

        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('unfinished');
            $table->string('workflow_status')->default('awaiting_receiving');
            $table->timestamps();
        });
        Schema::create('bill_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bill_id');
            $table->decimal('quantity', 14, 4);
            $table->decimal('ordered_quantity', 14, 4);
            $table->decimal('received_owned_quantity', 14, 4)->default(0);
            $table->decimal('custody_quantity', 14, 4)->default(0);
            $table->decimal('damaged_quantity', 14, 4)->default(0);
            $table->decimal('mismatched_quantity', 14, 4)->default(0);
            $table->decimal('missing_amount', 14, 4)->default(0);
            $table->string('status')->default('unfinished');
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
            $table->unsignedBigInteger('bill_item_id');
            $table->decimal('accepted_quantity', 14, 4)->default(0);
            $table->timestamps();
        });
        Schema::create('purchase_issue_resolutions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bill_id');
            $table->unsignedBigInteger('bill_item_id');
            $table->string('issue_type');
            $table->string('resolution');
            $table->decimal('quantity', 14, 4);
            $table->timestamps();
        });
        Schema::create('purchase_amanat_stocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bill_id');
            $table->unsignedBigInteger('bill_item_id');
            $table->timestamps();
        });
        Schema::create('inventory_cost_layers', function (Blueprint $table) {
            $table->id();
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_cost', 14, 4);
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        DB::disconnect('purchase_workflow_test');
        DB::purge('purchase_workflow_test');
        config()->set('database.default', $this->originalConnection);

        parent::tearDown();
    }

    public function test_open_damage_accounts_for_ordered_units_without_reopening_receiving(): void
    {
        [$bill, $item] = $this->purchase(10, 5, 5, 0);
        $this->receipt($bill, $item, 5);

        $service = app(PurchaseWorkflowStateService::class);
        $this->assertEqualsWithDelta(0, $service->remainingToReceive($item->fresh()), 0.0001);
        $this->assertSame('receiving_issues', $service->refresh($bill)->workflow_status);
        $this->assertSame('damaged', $item->fresh()->status);
    }

    public function test_purchased_extra_does_not_replace_units_expected_from_supplier(): void
    {
        [$bill, $item] = $this->purchase(10, 6, 0, 0);
        $this->receipt($bill, $item, 5);
        DB::table('purchase_issue_resolutions')->insert([
            'bill_id' => $bill->id,
            'bill_item_id' => $item->id,
            'issue_type' => 'damaged',
            'resolution' => 'replacement_expected',
            'quantity' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(PurchaseWorkflowStateService::class);
        $this->assertEqualsWithDelta(5, $service->remainingToReceive($item->fresh()), 0.0001);
        $this->assertSame('partially_received', $service->refresh($bill)->workflow_status);
        $this->assertSame('unfinished', $item->fresh()->status);
    }

    public function test_extra_is_not_treated_as_ordered_receipt_when_no_good_unit_arrived(): void
    {
        [$bill, $item] = $this->purchase(1, 1, 0, 0);
        $this->receipt($bill, $item, 0);
        DB::table('purchase_amanat_stocks')->insert([
            'bill_id' => $bill->id,
            'bill_item_id' => $item->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(PurchaseWorkflowStateService::class);
        $this->assertEqualsWithDelta(1, $service->remainingToReceive($item->fresh()), 0.0001);
        $this->assertSame('partially_received', $service->refresh($bill)->workflow_status);
    }

    public function test_finalization_guard_rejects_remaining_units_and_open_custody(): void
    {
        [$bill, $item] = $this->purchase(10, 5, 0, 1);
        $this->receipt($bill, $item, 5);

        $this->expectException(\RuntimeException::class);
        app(PurchaseWorkflowStateService::class)->assertReadyForFinalization($bill);
    }

    public function test_recognized_total_keeps_each_receipt_issue_and_extra_price(): void
    {
        [$bill, $item] = $this->purchase(10, 11, 0, 0);
        $this->receipt($bill, $item, 5);
        $receiptItemId = (int) DB::table('purchase_receipt_items')->value('id');
        $amanatId = DB::table('purchase_amanat_stocks')->insertGetId([
            'bill_id' => $bill->id,
            'bill_item_id' => $item->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventory_cost_layers')->insert([
            [
                'quantity' => 5,
                'unit_cost' => 5,
                'source_type' => 'purchase_receipt_item',
                'source_id' => $receiptItemId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'quantity' => 5,
                'unit_cost' => 4,
                'source_type' => 'purchase_issue_resolution',
                'source_id' => $item->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'quantity' => 1,
                'unit_cost' => 3,
                'source_type' => 'purchase_amanat_purchase',
                'source_id' => $amanatId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->assertEqualsWithDelta(
            48,
            app(PurchaseWorkflowStateService::class)->recognizedTotal($bill),
            0.0001,
        );
    }

    /** @return array{Bill, BillItem} */
    private function purchase(float $ordered, float $owned, float $damaged, float $custody): array
    {
        $billId = DB::table('bills')->insertGetId([
            'status' => 'unfinished',
            'workflow_status' => 'awaiting_receiving',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $itemId = DB::table('bill_items')->insertGetId([
            'bill_id' => $billId,
            'quantity' => $ordered,
            'ordered_quantity' => $ordered,
            'received_owned_quantity' => $owned,
            'custody_quantity' => $custody,
            'damaged_quantity' => $damaged,
            'mismatched_quantity' => 0,
            'missing_amount' => 0,
            'status' => 'unfinished',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [Bill::query()->findOrFail($billId), BillItem::query()->findOrFail($itemId)];
    }

    private function receipt(Bill $bill, BillItem $item, float $accepted): void
    {
        $receiptId = DB::table('purchase_receipts')->insertGetId([
            'bill_id' => $bill->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('purchase_receipt_items')->insert([
            'purchase_receipt_id' => $receiptId,
            'bill_item_id' => $item->id,
            'accepted_quantity' => $accepted,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
