<?php

namespace Tests\Feature\OnlineStore;

use App\Models\City;
use App\Models\Store\StoreShiplyCity;
use App\Models\Store\StoreShiplyVillage;
use App\Services\OnlineStore\OnlineStoreCheckoutService;
use App\Services\ShiplyService;
use App\Support\ShiplySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\Support\OnlineStoreCreditFixture;
use Tests\TestCase;

class StoreDeliverySafetyTest extends TestCase
{
    use RefreshDatabase {
        refreshTestDatabase as private refreshDisposableDatabase;
    }

    protected function refreshTestDatabase(): void
    {
        $this->requireDisposableDatabase();
        if (filter_var(env('ONLINE_STORE_PREMIGRATED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            $this->beginDatabaseTransaction();
            return;
        }
        $this->refreshDisposableDatabase();
    }

    private function destination(): int
    {
        $mode = ShiplySettings::mode();
        StoreShiplyCity::query()->forceCreate(['mode' => $mode, 'shiply_id' => 81001, 'name' => 'Delivery test city']);
        StoreShiplyVillage::query()->forceCreate(['mode' => $mode, 'shiply_id' => 81002,
            'shiply_city_id' => 81001, 'name' => 'Delivery test village', 'is_closed' => false]);

        return 81002;
    }

    public function test_carrier_failure_is_not_a_successful_zero_quote(): void
    {
        $village = $this->destination();
        $shiply = Mockery::mock(ShiplyService::class);
        $shiply->shouldReceive('calculateDeliveryCost')->once()->andThrow(new RuntimeException('carrier unavailable'));
        $this->app->instance(ShiplyService::class, $shiply);
        $this->postJson('/Cities/CalculateDeliveryFee', ['villageId' => $village, 'price' => 100])
            ->assertStatus(503)->assertJsonPath('code', 'delivery_quote_unavailable');
    }

    public function test_explicit_zero_carrier_quote_is_valid_but_missing_price_is_not(): void
    {
        $village = $this->destination();
        $shiply = Mockery::mock(ShiplyService::class);
        $shiply->shouldReceive('calculateDeliveryCost')->twice()->andReturn(['delivery_cost' => 0], []);
        $this->app->instance(ShiplyService::class, $shiply);
        $this->postJson('/Cities/CalculateDeliveryFee', ['villageId' => $village])->assertOk()->assertJsonPath('deliveryCost', 0);
        $this->postJson('/Cities/CalculateDeliveryFee', ['villageId' => $village])
            ->assertStatus(503)->assertJsonPath('code', 'delivery_quote_unavailable');
    }

    public function test_configured_free_city_delivery_does_not_call_carrier(): void
    {
        $village = $this->destination();
        $city = City::query()->create(['name_ar' => 'Delivery test city', 'shiply_area_code' => '81001', 'is_active' => true]);
        $city->deliveryFees()->create(['fee' => 0, 'effective_from' => now()]);
        $shiply = Mockery::mock(ShiplyService::class);
        $shiply->shouldNotReceive('calculateDeliveryCost');
        $this->app->instance(ShiplyService::class, $shiply);
        $this->postJson('/Cities/CalculateDeliveryFee', ['villageId' => $village])->assertOk()->assertJsonPath('deliveryCost', 0);
    }

    public function test_failed_checkout_quote_leaves_orders_stock_and_discounts_untouched(): void
    {
        $fixture = OnlineStoreCreditFixture::create('customer', 2000, 10, 100);
        $village = $this->destination();
        $shiply = Mockery::mock(ShiplyService::class);
        $shiply->shouldReceive('calculateDeliveryCost')->once()->andThrow(new RuntimeException('carrier unavailable'));
        $this->app->instance(ShiplyService::class, $shiply);
        $payload = OnlineStoreCreditFixture::nativePayload($fixture['listing']->id, 'failed-shipping-quote', 'cash');
        $payload['shiply_city_id'] = 81001;
        $payload['shiply_village_id'] = $village;
        $tables = ['sales_orders', 'sales_order_items', 'sales_order_status_logs', 'online_store_coupon_redemptions', 'debt_transactions'];
        $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
        try {
            app(OnlineStoreCheckoutService::class)->checkout($fixture['actor'], $payload);
            $this->fail('A missing shipping quote must abort checkout.');
        } catch (\App\Exceptions\DeliveryQuoteUnavailableException) {
            foreach ($before as $table => $count) {
                $this->assertSame($count, DB::table($table)->count(), $table);
            }
            $this->assertSame(10, (int) $fixture['product']->fresh()->stock);
        }
    }
}
