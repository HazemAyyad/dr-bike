<?php

namespace Tests\Feature\OnlineStore;

use App\Services\OnlineStore\OnlineStoreCheckoutService;
use App\Services\OnlineStore\StoreCreditService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\OnlineStoreCreditFixture;
use Tests\TestCase;

class StoreCreditConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (filter_var(env('ONLINE_STORE_DUMP_DERIVED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Fresh-schema tests cannot alter restored dump-derived state.');
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    public function test_ineligible_expired_over_limit_and_currency_mismatch_are_rejected(): void
    {
        foreach ([
            ['eligible' => false, 'limit' => 2000, 'expires' => null, 'currency' => 'ILS'],
            ['eligible' => true, 'limit' => 2000, 'expires' => now()->subMinute()->toISOString(), 'currency' => 'ILS'],
            ['eligible' => true, 'limit' => 1999, 'expires' => null, 'currency' => 'ILS'],
            ['eligible' => true, 'limit' => 2000, 'expires' => null, 'currency' => 'USD'],
        ] as $index => $case) {
            $policyCurrency = $case['currency'] === 'USD' ? 'USD' : 'ILS';
            $fixture = OnlineStoreCreditFixture::create('customer', 2000, 10, $case['limit'], $case['eligible'], $case['expires'], $policyCurrency);
            $payload = OnlineStoreCreditFixture::nativePayload($fixture['listing']->id, 'credit-reject-'.$index, 'credit', 0);
            try {
                app(OnlineStoreCheckoutService::class)->checkout($fixture['actor'], $payload);
                $this->fail('Invalid Store credit must be rejected.');
            } catch (ValidationException) {
                $this->assertDatabaseMissing('sales_orders', ['client_request_id' => 'credit-reject-'.$index]);
            }
        }
    }

    public function test_locked_ledger_exposure_prevents_two_attempts_consuming_one_limit(): void
    {
        $fixture = OnlineStoreCreditFixture::create('customer', 1500, 10, 2000);
        $service = app(OnlineStoreCheckoutService::class);
        $first = $service->checkout($fixture['actor'], OnlineStoreCreditFixture::nativePayload($fixture['listing']->id, 'limit-first', 'credit', 0));

        $this->expectException(ValidationException::class);
        try {
            $service->checkout($fixture['actor'], OnlineStoreCreditFixture::nativePayload($fixture['listing']->id, 'limit-second', 'credit', 0));
        } finally {
            $summary = app(StoreCreditService::class)->summary($fixture['link']);
            $this->assertSame(1500.0, $summary['current_debt']);
            $this->assertSame(0.0, $summary['pending_order_exposure']);
            $this->assertSame(1500.0, $summary['total_exposure']);
            $this->assertSame(500.0, $summary['available_credit']);
            $this->assertDatabaseCount('sales_orders', 1);
            $this->assertSame($first['order']->id, DB::table('sales_orders')->value('id'));
        }
    }

    public function test_stock_failure_rolls_back_without_order_finance_or_status_effects(): void
    {
        $fixture = OnlineStoreCreditFixture::create('customer', 2000, 0, 2000);
        $before = [
            'orders' => DB::table('sales_orders')->count(),
            'debt' => DB::table('debt_transactions')->count(),
            'status' => DB::table('sales_order_status_logs')->count(),
            'notifications' => DB::table('admin_notifications')->count(),
        ];

        try {
            app(OnlineStoreCheckoutService::class)->checkout($fixture['actor'], OnlineStoreCreditFixture::nativePayload($fixture['listing']->id, 'stock-failure', 'credit', 0));
            $this->fail('Out-of-stock checkout must fail.');
        } catch (ValidationException) {
            $this->assertSame($before['orders'], DB::table('sales_orders')->count());
            $this->assertSame($before['debt'], DB::table('debt_transactions')->count());
            $this->assertSame($before['status'], DB::table('sales_order_status_logs')->count());
            $this->assertSame($before['notifications'], DB::table('admin_notifications')->count());
        }
    }
}
