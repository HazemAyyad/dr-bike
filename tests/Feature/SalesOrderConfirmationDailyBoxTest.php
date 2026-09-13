<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SalesDailySession;
use App\Models\SalesOrderSettlement;
use App\Models\User;
use App\Services\SalesDailySessionService;
use App\Services\SalesOrderService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SalesOrderConfirmationDailyBoxTest extends TestCase
{
    use DatabaseTransactions;

    public function test_draft_payment_is_deferred_until_confirmation_with_an_open_drawer(): void
    {
        $user = User::factory()->create(['type' => 'admin']);
        $product = $this->product(stock: 10);
        $service = app(SalesOrderService::class);
        $this->closeOpenSalesOrderSessions();

        $order = $service->store($user, new Request([
            'customer_name' => 'Daily drawer test',
            'payment_type' => 'mixed',
            'payment_amount' => 25,
            'reserve_stock' => false,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => 50,
            ]],
        ]));

        $this->assertSame('unconfirmed', $order->status);
        $this->assertSame(25.0, (float) $order->payment_amount);
        $this->assertFalse(SalesOrderSettlement::query()
            ->where('sales_order_id', $order->id)
            ->exists());

        try {
            $service->confirm($user, $order->id);
            $this->fail('Confirming without an open sales-orders drawer must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('session', $exception->errors());
        }

        app(SalesDailySessionService::class)->openSession(
            $user,
            confirmOpeningVariance: true,
            sessionType: SalesDailySessionService::TYPE_SALES_ORDERS,
        );

        $confirmed = $service->confirm($user, $order->id);

        $this->assertSame('confirmed', $confirmed->status);
        $this->assertDatabaseHas('sales_order_settlements', [
            'sales_order_id' => $order->id,
            'source' => 'order_payment',
            'amount' => 25,
        ]);
    }

    public function test_zero_payment_order_still_requires_an_open_drawer_to_confirm(): void
    {
        $user = User::factory()->create(['type' => 'admin']);
        $product = $this->product(stock: 10);
        $service = app(SalesOrderService::class);
        $this->closeOpenSalesOrderSessions();

        $order = $service->store($user, new Request([
            'customer_name' => 'Zero payment drawer test',
            'payment_type' => 'credit',
            'payment_amount' => 0,
            'reserve_stock' => false,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => 50,
            ]],
        ]));

        $this->expectException(ValidationException::class);
        $service->confirm($user, $order->id);
    }

    private function closeOpenSalesOrderSessions(): void
    {
        SalesDailySession::query()
            ->where('session_type', SalesDailySessionService::TYPE_SALES_ORDERS)
            ->whereIn('status', [
                config('sales_daily.session_status.open'),
                config('sales_daily.session_status.closing_requested'),
            ])
            ->update([
                'status' => config('sales_daily.session_status.closed'),
                'closed_at' => now(),
            ]);
    }

    private function product(int $stock): Product
    {
        $id = (int) (Product::withTrashed()->max('id') ?? 0) + 1;

        return Product::withoutEvents(fn () => Product::query()->create([
            'id' => $id,
            'product_code' => (string) $id,
            'nameAr' => 'Sales order '.$id,
            'nameEng' => 'Sales order '.$id,
            'stock' => $stock,
            'normailPrice' => 50,
            'wholesalePrice' => 30,
        ]));
    }
}
