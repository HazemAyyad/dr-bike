<?php

namespace Tests\Feature\OnlineStore;

use App\Models\AccountingJournalEntry;
use App\Models\Box;
use App\Models\SalesOrder;
use App\Models\SalesOrderSettlement;
use App\Models\User;
use App\Services\AccountingProjectionService;
use App\Services\OnlineStore\CouponService;
use App\Services\OnlineStore\OnlineStoreCheckoutService;
use App\Services\OnlineStore\StoreCreditService;
use App\Services\SalesDailySessionService;
use App\Services\SalesOrderNotificationService;
use App\Services\SalesOrderService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
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
        $this->openSalesOrderSession($fixture['admin']);
        $payload = OnlineStoreCreditFixture::nativePayload($fixture['listing']->id, 'partial-'.$role, 'mixed', 500, $role);
        $first = app(OnlineStoreCheckoutService::class)->checkout($fixture['actor'], $payload);
        $order = $first['order'];

        $this->assertTrue($first['created']);
        $this->assertSame(2000.0, (float) $order->total);
        $this->assertSame(500.0, (float) $order->payment_amount);
        $settlement = SalesOrderSettlement::query()
            ->where('sales_order_id', $order->id)
            ->where('source', 'order_payment')
            ->sole();
        $this->assertSame(500.0, (float) $settlement->amount);
        $this->assertSame('sales-order-initial-payment-'.$order->id, $settlement->idempotency_key);
        $this->assertSame(500.0, (float) Box::query()->findOrFail($settlement->box_id)->total);
        $this->assertSame(1, DB::table('box_logs')->where('box_id', $settlement->box_id)
            ->where('type', 'add')->where('value', 500)->count());

        $ledger = DB::table('debt_transactions')->where('source', 'sales_order')
            ->where('source_id', $order->id)->whereNull('deleted_at')->sole();
        $this->assertSame(1500.0, (float) $ledger->amount);
        $this->assertSame('given', $ledger->type);
        $this->assertSame($role === 'customer' ? $fixture['party']->id : null, $ledger->customer_id);
        $this->assertSame($role === 'seller' ? $fixture['party']->id : null, $ledger->seller_id);
        $this->assertSame('sales_order', $ledger->source);
        $this->assertSame($order->id, $ledger->source_id);
        $this->assertNull($role === 'customer' ? $ledger->seller_id : $ledger->customer_id);

        $entry = AccountingJournalEntry::query()
            ->where('source_key', 'sales_order_settlement:'.$settlement->id)
            ->sole();
        $entry->load('lines.account');
        $this->assertSame('sales_order_settlement:'.$settlement->id, $entry->source_key);
        $this->assertTrue($entry->lines()->where($role.'_id', $fixture['party']->id)->exists());
        $this->assertFalse($entry->lines()->whereNotNull($role === 'customer' ? 'seller_id' : 'customer_id')->exists());
        $this->assertSame(500.0, (float) $entry->lines->firstWhere('account.system_key', 'cash')->debit);
        $this->assertSame(500.0, (float) $entry->lines->firstWhere('account.system_key', 'customer_deposits')->credit);

        $retry = app(OnlineStoreCheckoutService::class)->checkout($fixture['actor'], $payload);
        $this->assertFalse($retry['created']);
        $this->assertSame($order->id, $retry['order']->id);
        $this->assertSame(1, SalesOrder::query()->where('client_request_id', 'partial-'.$role)->count());
        $this->assertSame(1, SalesOrderSettlement::query()->where('sales_order_id', $order->id)->count());
        $this->assertSame(500.0, (float) Box::query()->findOrFail($settlement->box_id)->total);
        $this->assertSame(1, DB::table('box_logs')->where('box_id', $settlement->box_id)
            ->where('type', 'add')->where('value', 500)->count());
        $this->assertSame(1, DB::table('debt_transactions')->where('source', 'sales_order')
            ->where('source_id', $order->id)->whereNull('deleted_at')->count());
        $this->assertSame(1, AccountingJournalEntry::query()
            ->where('source_key', 'sales_order_settlement:'.$settlement->id)->count());
        $this->assertSame(1, DB::table('sales_order_status_logs')->where('sales_order_id', $order->id)->count());
        $notifications = DB::table('admin_notifications')->where('related_type', 'sales_order')
            ->where('related_id', $order->id);
        $this->assertSame(2, (clone $notifications)->count());
        $this->assertSame(1, (clone $notifications)->whereNull('recipient_user_id')->count());
        $this->assertSame(1, (clone $notifications)->where('recipient_user_id', $fixture['actor']->id)->count());

        app(SalesOrderService::class)->cancel($fixture['actor'], $order->id, 'Store cancellation regression');
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
        $this->assertSame(1, DB::table('debt_transactions')->where('source', 'sales_order')
            ->where('source_id', $first['order']->id)->whereNull('deleted_at')->count());
        $this->assertSame(0, SalesOrderSettlement::query()->where('sales_order_id', $first['order']->id)->count());
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
        $this->openSalesOrderSession($fixture['admin']);
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
        $summary = app(StoreCreditService::class)->summary($fixture['link']);
        $this->assertSame(1490.0, $summary['current_debt']);
        $this->assertSame(0.0, $summary['pending_order_exposure']);
        $this->assertSame(1490.0, $summary['total_exposure']);
    }

    public function test_failure_after_financial_preparation_rolls_back_every_checkout_effect(): void
    {
        $fixture = OnlineStoreCreditFixture::create('customer', 2000, 10, 2000);
        $this->openSalesOrderSession($fixture['admin']);
        app(CouponService::class)->save($fixture['admin'], [
            'code' => 'ROLLBACK10', 'discount_type' => 'fixed', 'discount_value' => 10,
            'minimum_order' => 0, 'total_usage_limit' => 1, 'per_user_usage_limit' => 1,
            'eligible_account_type' => 'customer', 'applies_to' => 'retail',
            'scope' => 'global', 'is_active' => true, 'targets' => [],
        ]);
        $payload = OnlineStoreCreditFixture::nativePayload($fixture['listing']->id, 'credit-rollback', 'mixed', 500);
        $payload['coupon_code'] = 'rollback10';
        $before = [
            'orders' => DB::table('sales_orders')->count(),
            'items' => DB::table('sales_order_items')->count(),
            'settlements' => DB::table('sales_order_settlements')->count(),
            'debts' => DB::table('debt_transactions')->count(),
            'journals' => DB::table('accounting_journal_entries')->count(),
            'box_total' => (float) DB::table('boxes')->sum('total'),
            'box_logs' => DB::table('box_logs')->count(),
            'statuses' => DB::table('sales_order_status_logs')->count(),
            'notifications' => DB::table('admin_notifications')->count(),
            'redemptions' => DB::table('online_store_coupon_redemptions')->count(),
        ];

        $notifications = Mockery::mock(SalesOrderNotificationService::class);
        $notifications->shouldReceive('notifyStatusChange')->once()
            ->andReturnUsing(function (SalesOrder $order): void {
                $settlement = SalesOrderSettlement::query()->where('sales_order_id', $order->id)->sole();
                $this->assertDatabaseHas('debt_transactions', [
                    'source' => 'sales_order', 'source_id' => $order->id, 'type' => 'given', 'amount' => 1490,
                ]);
                $this->assertDatabaseHas('accounting_journal_entries', [
                    'source_key' => 'sales_order_settlement:'.$settlement->id,
                ]);
                $this->assertDatabaseHas('online_store_coupon_redemptions', ['sales_order_id' => $order->id]);

                throw new RuntimeException('Forced failure after checkout financial preparation.');
            });
        $this->app->instance(SalesOrderNotificationService::class, $notifications);

        try {
            app(OnlineStoreCheckoutService::class)->checkout($fixture['actor'], $payload);
            $this->fail('The forced post-financial failure must abort checkout.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced failure after checkout financial preparation.', $exception->getMessage());
        }

        $this->assertSame($before['orders'], DB::table('sales_orders')->count());
        $this->assertSame($before['items'], DB::table('sales_order_items')->count());
        $this->assertSame($before['settlements'], DB::table('sales_order_settlements')->count());
        $this->assertSame($before['debts'], DB::table('debt_transactions')->count());
        $this->assertSame($before['journals'], DB::table('accounting_journal_entries')->count());
        $this->assertSame($before['box_total'], (float) DB::table('boxes')->sum('total'));
        $this->assertSame($before['box_logs'], DB::table('box_logs')->count());
        $this->assertSame($before['statuses'], DB::table('sales_order_status_logs')->count());
        $this->assertSame($before['notifications'], DB::table('admin_notifications')->count());
        $this->assertSame($before['redemptions'], DB::table('online_store_coupon_redemptions')->count());
        $this->assertSame(10, (int) $fixture['product']->fresh()->stock);
    }

    public function test_accounting_projection_failure_rolls_back_order_settlement_debt_and_coupon(): void
    {
        $fixture = OnlineStoreCreditFixture::create('customer', 2000, 10, 2000);
        $this->openSalesOrderSession($fixture['admin']);
        app(CouponService::class)->save($fixture['admin'], [
            'code' => 'FINANCEFAIL', 'discount_type' => 'fixed', 'discount_value' => 10,
            'minimum_order' => 0, 'total_usage_limit' => 1, 'per_user_usage_limit' => 1,
            'eligible_account_type' => 'customer', 'applies_to' => 'retail',
            'scope' => 'global', 'is_active' => true, 'targets' => [],
        ]);
        $payload = OnlineStoreCreditFixture::nativePayload($fixture['listing']->id, 'finance-failure', 'mixed', 500);
        $payload['coupon_code'] = 'financefail';
        $before = [
            'orders' => DB::table('sales_orders')->count(),
            'settlements' => DB::table('sales_order_settlements')->count(),
            'debts' => DB::table('debt_transactions')->count(),
            'journals' => DB::table('accounting_journal_entries')->count(),
            'box_total' => (float) DB::table('boxes')->sum('total'),
            'box_logs' => DB::table('box_logs')->count(),
            'redemptions' => DB::table('online_store_coupon_redemptions')->count(),
        ];

        $accounting = Mockery::mock(AccountingProjectionService::class);
        $accounting->shouldReceive('syncOrFail')->once()
            ->andThrow(new RuntimeException('Forced authoritative accounting failure.'));
        $this->app->instance(AccountingProjectionService::class, $accounting);

        try {
            app(OnlineStoreCheckoutService::class)->checkout($fixture['actor'], $payload);
            $this->fail('An accounting failure must abort Store checkout.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced authoritative accounting failure.', $exception->getMessage());
        }

        $this->assertSame($before['orders'], DB::table('sales_orders')->count());
        $this->assertSame($before['settlements'], DB::table('sales_order_settlements')->count());
        $this->assertSame($before['debts'], DB::table('debt_transactions')->count());
        $this->assertSame($before['journals'], DB::table('accounting_journal_entries')->count());
        $this->assertSame($before['box_total'], (float) DB::table('boxes')->sum('total'));
        $this->assertSame($before['box_logs'], DB::table('box_logs')->count());
        $this->assertSame($before['redemptions'], DB::table('online_store_coupon_redemptions')->count());
        $this->assertDatabaseMissing('sales_orders', ['client_request_id' => 'finance-failure']);
        $this->assertSame(10, (int) $fixture['product']->fresh()->stock);
    }

    private function openSalesOrderSession(User $admin): void
    {
        app(SalesDailySessionService::class)->openSession(
            $admin,
            confirmOpeningVariance: true,
            sessionType: SalesDailySessionService::TYPE_SALES_ORDERS,
        );
    }
}
