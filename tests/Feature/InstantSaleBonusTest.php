<?php

namespace Tests\Feature;

use App\Http\Middleware\RefreshSanctumTokenExpiry;
use App\Models\Customer;
use App\Models\InstantSale;
use App\Models\Product;
use App\Models\User;
use App\Services\InventoryCostingService;
use App\Services\SalesDailySessionService;
use App\Services\SalesReturnService;
use App\Services\SuspendedInstantSaleService;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InstantSaleBonusTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshStoreSchema();
        Http::preventStrayRequests();
        $this->withoutMiddleware(RefreshSanctumTokenExpiry::class);
        $this->admin = User::factory()->create(['type' => 'admin']);
        Sanctum::actingAs($this->admin);
    }

    public function test_paid_and_bonus_units_of_the_same_product_keep_separate_cost_and_revenue(): void
    {
        $product = $this->product();
        $customer = Customer::create(['name' => 'Bonus customer']);
        $sessions = app(SalesDailySessionService::class);
        $session = $sessions->openSession($this->admin, confirmOpeningVariance: true);
        $box = $sessions->dailyBoxForSessionCurrency($session, 'شيكل');

        $payload = $this->salePayload($product);
        $payload['buyer_type'] = 'customer';
        $payload['buyer_id'] = $customer->id;
        $payload['payment_box_id'] = $box->id;
        $payload['other_products'] = [[
            'product_id' => $product->id,
            'quantity' => 1,
            'cost' => 0,
            'is_bonus' => '1',
            'type' => 'normal',
        ]];

        $saleId = $this->postJson('/api/create/instant/sale', $payload)
            ->assertOk()->assertJsonPath('status', 'success')->json('instant_sale_id');
        $sale = InstantSale::findOrFail($saleId);
        $bonus = $sale->subProducts()->sole();
        $this->assertFalse($sale->is_bonus);
        $this->assertTrue($bonus->is_bonus);
        $this->assertSame(7, (int) $product->fresh()->stock);
        $this->assertEquals(56, $sale->inventory_total_cost);
        $this->assertEquals(28, $bonus->inventory_total_cost);
        $this->assertEquals(100, $sale->total_cost);
        $this->assertEquals(100, $sale->payment_box_value);

        $this->postJson('/api/get/instant/sale/invoice', ['instant_sale_id' => $saleId])
            ->assertJsonPath('instant_sale_invoice.is_bonus', false)
            ->assertJsonPath('instant_sale_invoice.sub_products.0.is_bonus', true);

        $service = app(SalesReturnService::class);
        $returnPayload = [
            'person_type' => 'customer',
            'person_id' => $customer->id,
            'cash_refund_amount' => 0,
            'items' => [[
                'source_type' => 'instant_sale',
                'source_item_id' => $bonus->id,
                'quantity' => 1,
                'unit_price' => 10,
                'price_override_reason' => 'Test',
            ]],
        ];
        try {
            $service->create($this->admin, $returnPayload);
            $this->fail('Bonus items must never generate a refund.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }

        $returnPayload['items'][0]['unit_price'] = 0;
        $return = $service->create($this->admin, $returnPayload);
        $this->assertEquals(0, $return->total_amount);
        $this->assertEquals(0, $return->credit_amount);
        $this->assertSame(8, (int) $product->fresh()->stock);
    }

    public function test_bonus_flag_survives_draft_validation_and_cannot_have_a_sale_price(): void
    {
        $product = $this->product();
        $payload = $this->salePayload($product);
        $payload['is_bonus'] = true;
        $payload['cost'] = $payload['total_cost'] = $payload['payment_box_value'] = 0;

        $validated = app(SuspendedInstantSaleService::class)->validatePayload($payload);
        $this->assertTrue($validated['is_bonus']);
        $payload['cost'] = 50;
        $this->postJson('/api/create/instant/sale', $payload)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('errors.is_bonus.0', 'سعر بيع البونص يجب أن يكون صفراً.');
        $this->assertSame(10, (int) $product->fresh()->stock);
    }

    private function product(): Product
    {
        $id = (int) (Product::withTrashed()->max('id') ?? 0) + 1;
        $product = Product::withoutEvents(fn () => Product::create([
            'id' => $id, 'product_code' => (string) $id, 'nameAr' => 'خوذة', 'nameEng' => 'Helmet',
            'normailPrice' => 50, 'stock' => 0,
        ]));
        Product::withoutEvents(fn () => app(InventoryCostingService::class)->addOwnedStock(
            product: $product, quantity: 10, unitCost: 28, currency: 'شيكل',
            sourceType: 'opening_stock', sourceId: null,
        ));

        return $product->fresh();
    }

    private function salePayload(Product $product): array
    {
        return [
            'product_id' => $product->id, 'quantity' => 2, 'cost' => 50, 'discount' => 0,
            'total_cost' => 100, 'payment_box_value' => 100, 'type' => 'normal',
            'buyer_type' => 'unknown', 'is_bonus' => false,
        ];
    }
}
