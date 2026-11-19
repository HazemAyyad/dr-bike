<?php

namespace Tests\Feature;

use App\Services\SalesProfitReportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SalesProfitReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.profit_report_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('profit_report_test');
        $this->table('products', ['product_code', 'nameAr', 'nameEng'], ['category_id', 'store_section_id']);
        $this->table('categories', ['nameAr']);
        $this->table('store_sections', ['name']);
        $this->table('bills', ['status', 'workflow_status', 'currency']);
        $this->table('purchase_receipts', ['received_at'], ['bill_id']);
        $this->table('purchase_receipt_items', [], ['purchase_receipt_id', 'product_id'], ['accepted_quantity', 'unit_price']);
        $this->table('sizes', ['size']);
        $this->table('size_colors', ['colorAr']);
        $this->table('boxes', ['currency']);
        $this->table('instant_sales', ['serial_number', 'status', 'cancelled_at', 'sale_kind'],
            ['parent_id', 'sales_order_id', 'maintenance_id', 'product_id', 'size_id', 'size_color_id', 'payment_box_id', 'offer_package_id'],
            ['quantity', 'cost', 'discount', 'total_cost', 'inventory_total_cost', 'is_bonus']);
        $this->table('sales_orders', ['serial_number', 'status', 'financial_posted_at'], ['instant_sale_id'], ['total', 'discount', 'is_debt_collection']);
        $this->table('sales_order_items', ['product_name'], ['sales_order_id', 'product_id', 'size_id', 'size_color_id'], ['quantity', 'line_total', 'is_hidden']);
        $this->table('maintenance', ['status', 'end_date', 'deleted_at'], ['instant_sale_id'], ['invoice_total', 'discount']);
        $this->table('maintenance_products', [], ['maintenance_id', 'product_id', 'size_id', 'size_color_id'], ['quantity', 'line_total', 'inventory_total_cost']);
        $this->table('product_stock_movements', ['reference_type', 'type', 'note'], ['reference_id', 'product_id', 'size_id', 'size_color_id'], ['quantity', 'total_cost']);
        $this->table('profit_sales', ['status', 'cancelled_at'], ['payment_box_id'], ['total_cost']);
        $this->table('sales_returns', ['serial_number', 'status', 'cancelled_at', 'return_type', 'completed_at', 'currency'], ['instant_sale_id', 'sales_order_id'], ['total_amount']);
        $this->table('sales_return_items', ['product_name'], ['sales_return_id', 'instant_sale_id', 'product_id', 'size_id', 'size_color_id'], ['quantity', 'line_total', 'inventory_total_cost']);
        DB::table('products')->insert(['id' => 1, 'product_code' => 'P001', 'nameAr' => 'قطعة']);
    }

    public function test_all_sale_sources_use_saved_costs_and_discount_bonus_and_returns_reconcile(): void
    {
        $sale = $this->sale(['quantity' => 2, 'cost' => 50, 'total_cost' => 90, 'discount' => 10, 'inventory_total_cost' => 40]);
        $this->sale(['parent_id' => $sale, 'cost' => 0, 'quantity' => 1, 'is_bonus' => 1, 'total_cost' => 0, 'inventory_total_cost' => 20]);
        $repair = DB::table('maintenance')->insertGetId(['status' => 'delivered']);
        $this->sale(['maintenance_id' => $repair, 'cost' => 50, 'total_cost' => 80, 'discount' => 5, 'inventory_total_cost' => null]);
        $this->movement('maintenance', $repair, -1, 30);
        $order = $this->order(180, 20);
        DB::table('sales_order_items')->insert(['sales_order_id' => $order, 'product_id' => 1, 'quantity' => 2, 'line_total' => 200, 'is_hidden' => 0]);
        $this->movement('sales_order', $order, -2, 80);
        // A prior dispatch and restoration must not inflate the final order cost.
        $this->movement('sales_order', $order, -2, 70);
        $this->movement('sales_order', $order, 2, 70);
        $legacy = $this->order(50);
        $this->sale(['sales_order_id' => $legacy, 'cost' => 50, 'total_cost' => 50, 'inventory_total_cost' => 10]);
        $return = DB::table('sales_returns')->insertGetId(['instant_sale_id' => $sale, 'status' => 'completed', 'return_type' => 'direct', 'completed_at' => '2026-11-04 12:00:00', 'currency' => 'شيكل', 'total_amount' => 45]);
        DB::table('sales_return_items')->insert(['sales_return_id' => $return, 'instant_sale_id' => $sale, 'product_id' => 1, 'quantity' => 1, 'line_total' => 45, 'inventory_total_cost' => 20]);
        DB::table('profit_sales')->insert(['total_cost' => 10, 'created_at' => '2026-11-02 12:00:00']);

        $report = $this->report();
        $this->assertCount(6, $report['documents']);
        $this->assertSame(365.0, $report['summary']['sales_total']);
        $this->assertSame(160.0, $report['summary']['cost_total']);
        $this->assertSame(205.0, $report['summary']['profit']);
        $this->assertSame(128.13, $report['summary']['profit_percent']);
        foreach ($report['documents'] as $document) {
            $this->assertEqualsWithDelta($document['profit'], array_sum(array_column($document['lines'], 'profit')), 0.00001);
            $this->assertEqualsWithDelta($document['sales_total'], array_sum(array_column($document['lines'], 'sales_total')), 0.00001);
        }
        $document = collect($report['documents'])->firstWhere('key', 'instant_sale:'.$sale);
        $this->assertSame(1.0, $document['lines'][1]['bonus']);
        $this->assertSame(-20.0, $document['lines'][1]['profit']);
        $this->assertSame(50.0, collect($report['documents'])->firstWhere('source', 'maintenance')['profit']);
        $this->assertSame(80.0, collect($report['documents'])->firstWhere('key', 'sales_order:'.$order)['cost_total']);
        $this->assertSame([], $report['withdrawals']);
        $this->assertSame(90.0, $this->report(['source' => 'sale', 'include_returns' => false])['summary']['sales_total']);
    }

    public function test_missing_cost_and_foreign_currency_do_not_manufacture_profit_and_unbilled_stock_stays_separate(): void
    {
        $this->sale(['total_cost' => 100, 'inventory_total_cost' => null]);
        $pending = DB::table('sales_orders')->insertGetId(['status' => 'dispatched', 'total' => 90]);
        $this->sale(['sales_order_id' => $pending, 'total_cost' => 90]);
        $this->movement('sales_order', $pending, -2, 30);
        $this->movement('destruction', 9, -1, 8);
        $this->order(500, 0, ['is_debt_collection' => 1]);
        $this->sale(['status' => 'cancelled', 'total_cost' => 500]);
        $this->sale(['created_at' => '2026-10-31 12:00:00', 'total_cost' => 500]);
        DB::table('boxes')->insert(['id' => 1, 'currency' => 'USD']);
        $this->sale(['payment_box_id' => 1, 'total_cost' => 20, 'inventory_total_cost' => 5]);
        $report = $this->report();
        $this->assertCount(1, $report['documents']);
        $this->assertSame(100.0, $report['summary']['sales_total']);
        $this->assertNull($report['summary']['cost_total']);
        $this->assertNull($report['summary']['profit']);
        $this->assertSame(1, $report['summary']['missing_cost_count']);
        $this->assertCount(2, $report['withdrawals']);
        $this->assertSame(38.0, array_sum(array_column($report['withdrawals'], 'cost_total')));
        $foreign = $this->report(['currency' => 'دولار']);
        $this->assertSame(20.0, $foreign['summary']['sales_total']);
        $this->assertNull($foreign['summary']['profit']);
        $this->assertSame([], $foreign['withdrawals']);
    }

    public function test_reverse_links_and_legacy_repairs_and_service_only_invoices_are_included_once(): void
    {
        $invoice = $this->sale(['total_cost' => 50, 'inventory_total_cost' => 10]);
        $this->order(50, 0, ['instant_sale_id' => $invoice]);
        $repairInvoice = $this->sale(['total_cost' => 60, 'inventory_total_cost' => 20]);
        DB::table('maintenance')->insert(['status' => 'delivered', 'instant_sale_id' => $repairInvoice]);
        $repair = DB::table('maintenance')->insertGetId(['status' => 'delivered', 'invoice_total' => 70, 'discount' => 0, 'end_date' => '2026-11-03']);
        DB::table('maintenance_products')->insert(['maintenance_id' => $repair, 'product_id' => 1, 'quantity' => 1, 'line_total' => 40, 'inventory_total_cost' => 15]);
        $this->sale(['product_id' => null, 'total_cost' => 25]);
        $this->assertSame(4, $this->report()['summary']['document_count']);
        $this->assertSame(160.0, $this->report()['summary']['profit']);
        $this->assertSame(2, $this->report(['source' => 'maintenance'])['summary']['document_count']);
        $this->assertSame(1, $this->report(['source' => 'order'])['summary']['document_count']);
    }

    public function test_packages_include_component_and_bonus_costs_without_treating_the_package_price_as_service_revenue(): void
    {
        $package = $this->sale(['product_id' => null, 'offer_package_id' => 2, 'quantity' => 1, 'cost' => 100, 'total_cost' => 90, 'discount' => 10]);
        $this->sale(['parent_id' => $package, 'quantity' => 1, 'cost' => 0, 'total_cost' => 0, 'inventory_total_cost' => 10]);
        $this->sale(['parent_id' => $package, 'quantity' => 3, 'cost' => 0, 'total_cost' => 0, 'inventory_total_cost' => 30]);
        $this->sale(['parent_id' => $package, 'quantity' => 1, 'cost' => 0, 'total_cost' => 0, 'is_bonus' => 1, 'inventory_total_cost' => 5]);
        $report = $this->report();
        $this->assertSame(45.0, $report['summary']['profit']);
        $lines = $report['documents'][0]['lines'];
        $this->assertCount(3, $lines);
        $this->assertSame([22.5, 67.5, 0.0], array_column($lines, 'sales_total'));
        $this->assertSame(-5.0, $lines[2]['profit']);
        $this->sale(['product_id' => null, 'offer_package_id' => 9, 'total_cost' => 100]);
        $this->assertNull($this->report()['summary']['profit']);
    }

    public function test_variant_costs_and_stock_withdrawn_before_the_posting_period_are_preserved(): void
    {
        DB::table('size_colors')->insert([
            ['id' => 5, 'colorAr' => 'أصفر'], ['id' => 6, 'colorAr' => 'أحمر'],
        ]);
        $order = $this->order(100);
        DB::table('sales_order_items')->insert([
            ['sales_order_id' => $order, 'product_id' => 1, 'size_color_id' => 5, 'quantity' => 1, 'line_total' => 40, 'is_hidden' => 0],
            ['sales_order_id' => $order, 'product_id' => 1, 'size_color_id' => 6, 'quantity' => 2, 'line_total' => 60, 'is_hidden' => 0],
        ]);
        DB::table('product_stock_movements')->insert([
            ['reference_type' => 'sales_order', 'reference_id' => $order, 'product_id' => 1, 'size_color_id' => 5, 'quantity' => -1, 'total_cost' => 5, 'created_at' => '2026-10-31 12:00:00'],
            ['reference_type' => 'sales_order', 'reference_id' => $order, 'product_id' => 1, 'size_color_id' => 6, 'quantity' => -2, 'total_cost' => 30, 'created_at' => '2026-10-31 12:00:00'],
        ]);
        $this->assertSame(65.0, $this->report()['summary']['profit']);
        $this->assertSame([5.0, 30.0], array_column($this->report()['documents'][0]['lines'], 'cost_total'));
        $this->assertSame(['قطعة / أصفر', 'قطعة / أحمر'], array_column($this->report()['documents'][0]['lines'], 'product'));
        $this->sale(['sale_kind' => 'adjustment', 'total_cost' => 10, 'inventory_total_cost' => null]);
        $this->assertSame(75.0, $this->report()['summary']['profit']);
    }

    public function test_product_report_combines_variants_bonus_and_returns_and_keeps_receipts_separate(): void
    {
        DB::table('categories')->insert(['id' => 1, 'nameAr' => 'قطع غيار']);
        DB::table('store_sections')->insert(['id' => 2, 'name' => 'الرف الأول']);
        DB::table('products')->where('id', 1)->update(['category_id' => 1, 'store_section_id' => 2]);
        DB::table('products')->insert(['id' => 2, 'product_code' => 'P002', 'nameAr' => 'مشتريات فقط']);
        DB::table('size_colors')->insert([['id' => 5, 'colorAr' => 'أصفر'], ['id' => 6, 'colorAr' => 'أحمر']]);
        $sale = $this->sale(['size_color_id' => 5, 'quantity' => 2, 'cost' => 50, 'total_cost' => 90, 'discount' => 10, 'inventory_total_cost' => 60]);
        $bonus = $this->sale(['parent_id' => $sale, 'quantity' => 1, 'cost' => 0, 'total_cost' => 0, 'is_bonus' => 1, 'inventory_total_cost' => 10]);
        $this->sale(['size_color_id' => 6, 'quantity' => 1, 'cost' => 80, 'total_cost' => 80, 'inventory_total_cost' => 40]);
        $return = DB::table('sales_returns')->insertGetId(['instant_sale_id' => $sale, 'status' => 'completed', 'return_type' => 'direct', 'completed_at' => '2026-11-04', 'currency' => 'شيكل', 'total_amount' => 45]);
        DB::table('sales_return_items')->insert([
            ['sales_return_id' => $return, 'instant_sale_id' => $sale, 'product_id' => 1, 'quantity' => 1, 'line_total' => 45, 'inventory_total_cost' => 30],
            ['sales_return_id' => $return, 'instant_sale_id' => $bonus, 'product_id' => 1, 'quantity' => 1, 'line_total' => 0, 'inventory_total_cost' => 10],
        ]);
        $this->sale(['product_id' => null, 'total_cost' => 25]);
        $this->receipt(1, 10, 20);
        $this->receipt(2, 5, 10);
        $this->receipt(1, 50, 999, ['status' => 'cancelled']);
        $this->receipt(1, 50, 999, ['workflow_status' => 'cancelled']);
        $this->receipt(1, 50, 999, [], '2026-10-31');
        $this->receipt(1, 50, 999, ['currency' => 'USD']);

        $report = $this->report(['group_by' => 'product']);
        $data = $report['product_report'];
        $row = collect($data['rows'])->firstWhere('product_id', 1);
        $this->assertSame('قطعة', $row['product']);
        $this->assertSame(2.0, $row['sold_quantity']);
        $this->assertSame(0.0, $row['bonus_quantity']);
        $this->assertSame(10.0, $row['purchased_quantity']);
        $this->assertSame(200.0, $row['purchase_total']);
        $this->assertSame(70.0, $row['cost_total']);
        $this->assertSame(125.0, $row['sales_total']);
        $this->assertSame(55.0, $row['profit']);
        $this->assertSame(78.57, $row['profit_percent']);
        $this->assertSame(35.0, $row['unit_cost']);
        $this->assertSame(62.5, $row['price']);
        $this->assertSame(150.0, $data['summary']['sales_total']);
        $this->assertSame(80.0, $data['summary']['profit']);
        $this->assertSame(114.29, $data['summary']['profit_percent']);
        $this->assertSame(250.0, $data['summary']['purchase_total']);
        $this->assertSame($report['summary']['profit'], $data['summary']['profit']);
        $this->assertSame($report['summary']['sales_total'], $data['summary']['sales_total']);
        $this->assertSame(0.0, collect($data['rows'])->firstWhere('product_id', 2)['profit']);
        $this->assertCount(1, $data['charts']['top_products']);
        $this->assertCount(3, $data['charts']['comparison']);
        $this->assertSame('قطع غيار', $data['options']['categories'][0]->name);
    }

    public function test_product_filters_keep_allocated_discount_and_exclude_unassigned_services(): void
    {
        DB::table('products')->where('id', 1)->update(['category_id' => 1, 'store_section_id' => 2]);
        DB::table('products')->insert(['id' => 2, 'nameAr' => 'صنف آخر', 'category_id' => 9, 'store_section_id' => 3]);
        $sale = $this->sale(['total_cost' => 135, 'discount' => 15, 'inventory_total_cost' => 40]);
        $this->sale(['parent_id' => $sale, 'product_id' => 2, 'cost' => 50, 'total_cost' => 0, 'inventory_total_cost' => 20]);
        $this->sale(['product_id' => null, 'total_cost' => 25]);
        $this->receipt(1, 10, 20);
        $this->receipt(2, 10, 999);
        $data = $this->report(['group_by' => 'product', 'category_id' => 1, 'store_section_id' => 2])['product_report'];
        $this->assertCount(1, $data['rows']);
        $this->assertSame(90.0, $data['summary']['sales_total']);
        $this->assertSame(10.0, $data['summary']['discount']);
        $this->assertSame(50.0, $data['summary']['profit']);
        $this->assertSame(125.0, $data['summary']['profit_percent']);
        $this->assertSame(200.0, $data['summary']['purchase_total']);
        $purchasesOnly = $this->report(['group_by' => 'product', 'source' => 'maintenance', 'category_id' => 1])['product_report'];
        $this->assertSame(0.0, $purchasesOnly['summary']['sales_total']);
        $this->assertSame(200.0, $purchasesOnly['summary']['purchase_total']);
        $empty = $this->report(['group_by' => 'product', 'category_id' => 1, 'store_section_id' => 3])['product_report'];
        $this->assertSame([], $empty['rows']);
        $this->assertSame(0.0, $empty['summary']['profit']);
        $this->assertNull($empty['summary']['profit_percent']);
    }

    public function test_product_missing_cost_and_zero_quantity_are_not_replaced_with_receipt_prices(): void
    {
        $this->sale(['inventory_total_cost' => null]);
        $this->receipt(1, 2, 1);
        $data = $this->report(['group_by' => 'product'])['product_report'];
        $this->assertNull($data['rows'][0]['cost_total']);
        $this->assertNull($data['summary']['profit']);
        $this->assertSame([], $data['charts']['top_products']);
        $this->assertNull($data['charts']['comparison'][0]['profit']);
        $this->assertSame(1, $data['summary']['missing_cost_count']);
        DB::table('instant_sales')->update(['inventory_total_cost' => 40]);
        $return = DB::table('sales_returns')->insertGetId(['status' => 'completed', 'return_type' => 'direct', 'completed_at' => '2026-11-04', 'currency' => 'شيكل', 'total_amount' => 90]);
        DB::table('sales_return_items')->insert(['sales_return_id' => $return, 'product_id' => 1, 'quantity' => 1, 'line_total' => 90, 'inventory_total_cost' => 40]);
        $data = $this->report(['group_by' => 'product'])['product_report'];
        $this->assertSame(0.0, $data['rows'][0]['sold_quantity']);
        $this->assertNull($data['rows'][0]['unit_cost']);
        $this->assertNull($data['rows'][0]['price']);
        $this->assertSame(10.0, $data['summary']['profit']);
        $this->assertNull($data['summary']['profit_percent']);
        $withoutReturns = $this->report(['group_by' => 'product', 'include_returns' => false])['product_report'];
        $this->assertSame(60.0, $withoutReturns['summary']['profit']);
        $this->assertSame(150.0, $withoutReturns['summary']['profit_percent']);
        DB::table('boxes')->insert(['id' => 7, 'currency' => 'USD']);
        $this->sale(['payment_box_id' => 7, 'inventory_total_cost' => 40]);
        $foreign = $this->report(['group_by' => 'product', 'currency' => 'دولار'])['product_report'];
        $this->assertNull($foreign['summary']['profit']);
    }

    public function test_product_charts_reconcile_remainder_and_exclude_incomplete_profits_from_rankings(): void
    {
        for ($id = 1; $id <= 7; $id++) {
            if ($id > 1) {
                DB::table('products')->insert(['id' => $id, 'nameAr' => 'صنف '.$id]);
            }
            $this->sale(['product_id' => $id, 'cost' => $id * 100, 'total_cost' => $id * 100, 'inventory_total_cost' => $id === 1 ? null : $id * 50]);
        }
        $data = $this->report(['group_by' => 'product'])['product_report'];
        $this->assertCount(5, $data['charts']['top_products']);
        $this->assertCount(6, $data['charts']['comparison']);
        $other = $data['charts']['comparison'][5];
        $this->assertSame('بقية الأصناف', $other['product']);
        $this->assertSame(300.0, $other['sales_total']);
        $this->assertNull($other['profit']);
        $this->assertSame($data['summary']['sales_total'], array_sum(array_column($data['charts']['comparison'], 'sales_total')));
    }

    private function receipt(int $product, float $quantity, float $price, array $billFields = [], string $date = '2026-11-05'): void
    {
        $bill = DB::table('bills')->insertGetId($billFields + ['currency' => 'ILS', 'status' => 'completed']);
        $receipt = DB::table('purchase_receipts')->insertGetId(['bill_id' => $bill, 'received_at' => $date, 'created_at' => '2026-10-01']);
        DB::table('purchase_receipt_items')->insert(['purchase_receipt_id' => $receipt, 'product_id' => $product, 'accepted_quantity' => $quantity, 'unit_price' => $price]);
    }

    private function table(string $name, array $strings, array $ids = [], array $numbers = []): void
    {
        Schema::create($name, function (Blueprint $table) use ($strings, $ids, $numbers) {
            $table->id();
            foreach ($strings as $column) {
                $table->string($column)->nullable();
            }
            foreach ($ids as $column) {
                $table->unsignedBigInteger($column)->nullable();
            }
            foreach ($numbers as $column) {
                $table->double($column)->nullable();
            }
            $table->timestamps();
        });
    }

    private function sale(array $fields): int
    {
        return DB::table('instant_sales')->insertGetId($fields + ['product_id' => 1, 'quantity' => 1, 'cost' => 100, 'discount' => 0, 'is_bonus' => 0, 'total_cost' => 100, 'created_at' => '2026-11-02 12:00:00']);
    }

    private function order(float $total, float $discount = 0, array $fields = []): int
    {
        return DB::table('sales_orders')->insertGetId($fields + ['total' => $total, 'discount' => $discount, 'status' => 'delivered', 'is_debt_collection' => 0, 'financial_posted_at' => '2026-11-03 12:00:00']);
    }

    private function movement(string $type, int $id, float $quantity, ?float $cost): void
    {
        DB::table('product_stock_movements')->insert(['reference_type' => $type, 'reference_id' => $id, 'type' => $quantity < 0 ? 'sale' : 'sale_cancel', 'product_id' => 1, 'quantity' => $quantity, 'total_cost' => $cost, 'created_at' => '2026-11-02 12:00:00']);
    }

    private function report(array $filters = []): array
    {
        return app(SalesProfitReportService::class)->report($filters + ['from_date' => '2026-11-01', 'to_date' => '2026-11-09']);
    }
}
