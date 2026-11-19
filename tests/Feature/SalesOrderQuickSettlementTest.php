<?php

namespace Tests\Feature;

use App\Models\Box;
use App\Models\BoxLog;
use App\Models\Expense;
use App\Models\SalesOrder;
use App\Models\SalesOrderSettlement;
use App\Models\User;
use App\Services\SalesDailySessionService;
use App\Services\SalesOrderFulfillmentService;
use App\Services\SalesOrderNotificationService;
use App\Services\SalesOrderPurgeService;
use App\Services\SalesOrdersDailyBoxService;
use App\Services\SalesOrderService;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class SalesOrderQuickSettlementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshStoreSchema();
        Http::preventStrayRequests();
        $this->app->instance(SalesOrderNotificationService::class,
            Mockery::mock(SalesOrderNotificationService::class)->shouldIgnoreMissing());
    }

    private function fixture(float $carrier = 130, float $customer = 0): array
    {
        $user = User::factory()->create(['type' => 'admin']);
        $session = app(SalesDailySessionService::class)->openSession($user,
            confirmOpeningVariance: true, sessionType: SalesDailySessionService::TYPE_SALES_ORDERS);
        $box = app(SalesOrdersDailyBoxService::class)->boxesForSession($session)->first();
        $order = SalesOrder::create([
            'serial_number' => 'quick-'.uniqid(), 'status' => 'delivered',
            'customer_name' => 'Quick settlement', 'payment_type' => 'credit',
            'total' => $carrier + $customer, 'subtotal' => 60, 'customer_delivery_fee' => 70,
            'carrier_receivable_balance' => $carrier, 'customer_debt_balance' => $customer,
            'created_by' => $user->id, 'sales_daily_session_id' => $session->id,
        ]);

        return [$user, $session, $box, $order];
    }

    public function test_full_settlement_and_archive_are_atomic_and_retry_does_not_duplicate_cash_or_expense(): void
    {
        [$user, , $box, $order] = $this->fixture();
        $payload = ['delivery_settled_amount' => 130, 'carrier_fee' => 70,
            'archive_after_settlement' => true, 'idempotency_key' => 'quick-retry'];
        $service = app(SalesOrderFulfillmentService::class);
        $this->assertSame('archived', $service->settleDelivery($user, $order->id, $payload)->status);
        $this->assertSame('archived', $service->settleDelivery($user, $order->id, $payload)->status);
        $this->assertSame(60.0, (float) $box->fresh()->total);
        $this->assertSame(0.0, $order->fresh()->carrier_receivable_balance);
        $this->assertSame(1, SalesOrderSettlement::where('sales_order_id', $order->id)->count());
        $this->assertSame(1, Expense::where('payment_method', 'carrier_withholding')->count());
        $this->assertSame(1, BoxLog::where('box_id', $box->id)->where('type', 'add')->count());
    }

    public function test_archive_request_rolls_back_a_partial_settlement_including_its_expense_and_box_log(): void
    {
        [$user, , $box, $order] = $this->fixture();
        try {
            app(SalesOrderFulfillmentService::class)->settleDelivery($user, $order->id,
                ['delivery_settled_amount' => 50, 'carrier_fee' => 10, 'archive_after_settlement' => true]);
            $this->fail('An outstanding balance must prevent settlement-and-archive.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('order', $e->errors());
        }
        $this->assertSame(0.0, (float) $box->fresh()->total);
        $this->assertSame(130.0, $order->fresh()->carrier_receivable_balance);
        $this->assertSame(0, SalesOrderSettlement::where('sales_order_id', $order->id)->count());
        $this->assertSame(0, Expense::where('payment_method', 'carrier_withholding')->count());
        $this->assertSame(0, BoxLog::where('box_id', $box->id)->count());
    }

    public function test_partial_settlement_without_archive_remains_delivered_and_preserves_the_remainder(): void
    {
        [$user, , $box, $order] = $this->fixture();
        $settled = app(SalesOrderFulfillmentService::class)->settleDelivery($user, $order->id,
            ['delivery_settled_amount' => 50, 'carrier_fee' => 10]);
        $this->assertSame('delivered', $settled->status);
        $this->assertSame(80.0, $settled->carrier_receivable_balance);
        $this->assertSame(40.0, (float) $box->fresh()->total);
    }

    public function test_a_remaining_customer_debt_prevents_archive_even_after_full_carrier_collection(): void
    {
        [$user, , $box, $order] = $this->fixture(customer: 20);
        $this->expectException(ValidationException::class);
        try {
            app(SalesOrderFulfillmentService::class)->settleDelivery($user, $order->id,
                ['delivery_settled_amount' => 130, 'carrier_fee' => 70, 'archive_after_settlement' => true]);
        } finally {
            $this->assertSame(0.0, (float) $box->fresh()->total);
            $this->assertSame(20.0, $order->fresh()->customer_debt_balance);
        }
    }

    public function test_a_reused_reference_with_different_amount_is_rejected(): void
    {
        [$user, , , $order] = $this->fixture();
        $service = app(SalesOrderFulfillmentService::class);
        $service->settleDelivery($user, $order->id, ['delivery_settled_amount' => 50, 'idempotency_key' => 'same-key']);
        $this->expectException(ValidationException::class);
        $service->settleDelivery($user, $order->id, ['delivery_settled_amount' => 60, 'idempotency_key' => 'same-key']);
    }

    public function test_settlement_endpoint_accepts_archive_flag_and_returns_archived_detail(): void
    {
        [$user, , $box, $order] = $this->fixture();
        $this->withoutMiddleware()->actingAs($user)
            ->postJson('/api/sales/order/settle', [
                'sales_order_id' => $order->id, 'delivery_settled_amount' => 130,
                'carrier_fee' => 70, 'archive_after_settlement' => true, 'idempotency_key' => 'http-quick',
            ])->assertOk()->assertJsonPath('status', 'success')->assertJsonPath('sales_order.status', 'archived');
        $this->assertSame(60.0, (float) $box->fresh()->total);
    }

    public function test_pending_closing_drawer_cannot_accept_a_settlement(): void
    {
        [$user, $session, $box, $order] = $this->fixture();
        $session->update(['status' => 'closing_requested']);
        $this->expectException(ValidationException::class);
        try {
            app(SalesOrderFulfillmentService::class)->settleDelivery($user, $order->id,
                ['delivery_settled_amount' => 130, 'carrier_fee' => 70, 'archive_after_settlement' => true]);
        } finally {
            $this->assertSame(0.0, (float) $box->fresh()->total);
            $this->assertSame(130.0, $order->fresh()->carrier_receivable_balance);
        }
    }

    public function test_purge_cannot_reverse_money_already_in_a_closed_daily_drawer(): void
    {
        [$user, $session, $box, $order] = $this->fixture(375);
        app(SalesOrderFulfillmentService::class)->settleDelivery($user, $order->id,
            ['delivery_settled_amount' => 375]);
        Box::whereKey($box->id)->update(['total' => 0]);
        $session->update(['status' => 'closed', 'closed_at' => now()]);
        $purge = app(SalesOrderPurgeService::class);
        $preview = $purge->preview(now()->endOfDay());
        $this->assertFalse($preview['can_purge']);
        $this->assertSame(1, $preview['closed_drawer_cash_orders_count']);
        try {
            $purge->purge($user, now()->endOfDay(), $order->id);
            $this->fail('Closed drawer cash must not be reversed into a negative balance.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('orders', $e->errors());
        }
        $this->assertSame(0.0, (float) $box->fresh()->total);
        $this->assertNotNull($order->fresh());
        $this->assertSame(1, SalesOrderSettlement::where('sales_order_id', $order->id)->count());
    }

    public function test_opening_count_adjustment_is_recorded_with_actor_and_session(): void
    {
        $user = User::factory()->create(['type' => 'admin']);
        $box = Box::create(['name' => 'Old drawer', 'type' => 'daily_sales_orders',
            'user_id' => $user->id, 'total' => -375, 'currency' => 'شيكل', 'is_shown' => 0]);
        $session = app(SalesDailySessionService::class)->openSession($user,
            confirmOpeningVariance: true, salesOrdersOpeningCounts: [['currency' => 'شيكل', 'physical_count' => 0]],
            sessionType: SalesDailySessionService::TYPE_SALES_ORDERS);
        $this->assertSame(0.0, (float) $box->fresh()->total);
        $this->assertDatabaseHas('box_logs', ['box_id' => $box->id, 'value' => 375,
            'reason_code' => 'sales_orders_opening_variance', 'created_by' => $user->id,
            'source_id' => $session->id]);
    }

    public function test_financial_summary_is_independent_of_status_tab_and_list_limit_but_respects_origin(): void
    {
        [, , , $order] = $this->fixture();
        $order->forceFill(['origin' => 'admin'])->save();
        $rows = [];
        for ($i = 0; $i < 501; $i++) {
            $rows[] = ['serial_number' => 'summary-'.$i, 'status' => 'with_delivery',
                'origin' => 'store', 'carrier_receivable_balance' => 2, 'customer_debt_balance' => 1];
        }
        SalesOrder::insert($rows);
        $summary = app(SalesOrderService::class)->financialSummary(['status' => 'archived', 'origin' => 'store']);
        $this->assertSame(['carrier_receivable' => 1002.0, 'customer_debt' => 501.0], $summary);
        $this->assertSame(130.0, app(SalesOrderService::class)->financialSummary(['origin' => 'admin'])['carrier_receivable']);
    }
}
