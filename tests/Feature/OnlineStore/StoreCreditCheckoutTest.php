<?php

namespace Tests\Feature\OnlineStore;

use App\Models\Box;
use App\Models\SalesOrderSettlement;
use App\Services\AccountingProjectionService;
use App\Services\DebtLedgerService;
use App\Services\OnlineStore\CouponService;
use App\Services\OnlineStore\OnlineStoreCheckoutService;
use App\Services\OnlineStore\StoreCreditService;
use App\Services\SalesDailySessionService;
use App\Services\SalesOrderFulfillmentService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\OnlineStoreCreditFixture;
use Tests\TestCase;

class StoreCreditCheckoutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (filter_var(env('ONLINE_STORE_DUMP_DERIVED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Fresh-schema tests cannot alter restored dump-derived state.');
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    /** @dataProvider partyProvider */
    public function test_two_thousand_total_with_five_hundred_paid_posts_exact_party_reconciliation(string $role): void
    {
        $fixture = OnlineStoreCreditFixture::create($role);
        $payload = OnlineStoreCreditFixture::nativePayload($fixture['listing']->id, 'partial-'.$role, 'mixed', 500, $role);
        $order = app(OnlineStoreCheckoutService::class)->checkout($fixture['actor'], $payload)['order'];

        $this->assertSame(2000.0, (float) $order->total);
        $this->assertSame(500.0, (float) $order->payment_amount);
        $ledger = app(DebtLedgerService::class)->syncSalesOrderToLedger($order, 2000, 500);

        $this->assertNotNull($ledger);
        $this->assertSame(1500.0, (float) $ledger->amount);
        $this->assertSame('given', $ledger->type);
        $this->assertSame($role === 'customer' ? $fixture['party']->id : null, $ledger->customer_id);
        $this->assertSame($role === 'seller' ? $fixture['party']->id : null, $ledger->seller_id);
        $this->assertSame('sales_order', $ledger->source);
        $this->assertSame($order->id, $ledger->source_id);
        $ledgerRetry = app(DebtLedgerService::class)->syncSalesOrderToLedger($order, 2000, 500);
        $this->assertSame($ledger->id, $ledgerRetry?->id);
        $this->assertSame(1, DB::table('debt_transactions')->where('source', 'sales_order')
            ->where('source_id', $order->id)->whereNull('deleted_at')->count());

        $box = Box::query()->create(['name' => 'Store credit fixture', 'total' => 500, 'currency' => 'شيكل']);
        $settlement = SalesOrderSettlement::query()->create([
            'sales_order_id' => $order->id,
            'box_id' => $box->id,
            'source' => 'order_payment',
            'amount' => 500,
            'cash_amount' => 500,
            'carrier_fee' => 0,
            'customer_debt_before' => 0,
            'customer_debt_after' => 0,
            'carrier_receivable_before' => 0,
            'carrier_receivable_after' => 0,
            'idempotency_key' => 'store-credit-'.$role.'-'.$order->id,
            'created_by' => $fixture['admin']->id,
        ]);
        $entry = app(AccountingProjectionService::class)->syncOrFail($settlement);
        $this->assertNotNull($entry);
        $this->assertSame('sales_order_settlement:'.$settlement->id, $entry->source_key);
        $this->assertTrue($entry->lines()->where($role.'_id', $fixture['party']->id)->exists());
        $this->assertFalse($entry->lines()->where($role === 'customer' ? 'seller_id' : 'customer_id', $fixture['party']->id)->exists());

        app(SalesDailySessionService::class)->openSession(
            $fixture['admin'],
            confirmOpeningVariance: true,
            sessionType: SalesDailySessionService::TYPE_SALES_ORDERS,
        );
        $fulfillment = app(SalesOrderFulfillmentService::class);
        $fulfillment->reverseFinancialsForCancellation($order, $fixture['admin']);
        $fulfillment->reverseFinancialsForCancellation($order->fresh(), $fixture['admin']);
        $this->assertFalse(DB::table('debt_transactions')->where('source', 'sales_order')
            ->where('source_id', $order->id)->whereNull('deleted_at')->exists());
        $this->assertSame(1, SalesOrderSettlement::query()->where('sales_order_id', $order->id)
            ->where('source', 'cancellation_reversal')->count());
    }

    public static function partyProvider(): array
    {
        return [['customer'], ['seller']];
    }

    public function test_native_retry_does_not_duplicate_credit_order_or_source_keys(): void
    {
        $fixture = OnlineStoreCreditFixture::create();
        $payload = OnlineStoreCreditFixture::nativePayload($fixture['listing']->id, 'credit-retry', 'credit', 0);
        $first = app(OnlineStoreCheckoutService::class)->checkout($fixture['actor'], $payload);
        $retry = app(OnlineStoreCheckoutService::class)->checkout($fixture['actor'], $payload);

        $this->assertTrue($first['created']);
        $this->assertFalse($retry['created']);
        $this->assertSame($first['order']->id, $retry['order']->id);
        $this->assertSame(1, DB::table('sales_orders')->where('client_request_id', 'credit-retry')->count());
    }

    public function test_credit_summary_derives_debt_from_ledger_and_null_limit_is_explicitly_unlimited(): void
    {
        $fixture = OnlineStoreCreditFixture::create('customer', 100, 10, null);
        DB::table('debt_transactions')->insert([
            'customer_id' => $fixture['party']->id,
            'seller_id' => null,
            'type' => 'given',
            'amount' => 700,
            'currency' => 'شيكل',
            'balance_after' => 700,
            'note' => 'Store credit exposure fixture',
            'transaction_date' => now()->toDateString(),
            'source' => 'online_store_test_fixture',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('debt_transactions')->insert([
            'customer_id' => $fixture['party']->id,
            'seller_id' => null,
            'type' => 'taken',
            'amount' => 200,
            'currency' => 'شيكل',
            'balance_after' => 500,
            'note' => 'Store credit repayment fixture',
            'transaction_date' => now()->toDateString(),
            'source' => 'online_store_test_fixture',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $summary = app(StoreCreditService::class)->summary($fixture['link']);
        $this->assertSame(500.0, $summary['current_debt']);
        $this->assertTrue($summary['is_unlimited']);
        $this->assertNull($summary['available_credit']);
    }

    public function test_mixed_credit_checkout_keeps_coupon_pricing_and_reservation_compatible(): void
    {
        $fixture = OnlineStoreCreditFixture::create('customer', 2000, 10, 2000);
        app(CouponService::class)->save($fixture['admin'], [
            'code' => 'CREDIT10', 'discount_type' => 'fixed', 'discount_value' => 10,
            'minimum_order' => 0, 'total_usage_limit' => 1, 'per_user_usage_limit' => 1,
            'eligible_account_type' => 'customer', 'applies_to' => 'retail',
            'scope' => 'global', 'is_active' => true, 'targets' => [],
        ]);
        $payload = OnlineStoreCreditFixture::nativePayload($fixture['listing']->id, 'credit-coupon', 'mixed', 500);
        $payload['coupon_code'] = 'credit10';

        $order = app(OnlineStoreCheckoutService::class)->checkout($fixture['actor'], $payload)['order'];

        $this->assertSame(1990.0, (float) $order->total);
        $this->assertSame(500.0, (float) $order->payment_amount);
        $this->assertDatabaseHas('online_store_coupon_redemptions', [
            'sales_order_id' => $order->id, 'status' => 'reserved', 'discount_amount' => 10,
        ]);
        $this->assertSame(1490.0, app(StoreCreditService::class)->summary($fixture['link'])['pending_order_exposure']);
    }
}
