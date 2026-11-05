<?php

namespace Tests\Feature;

use App\Http\Middleware\RefreshSanctumTokenExpiry;
use App\Models\BoxLog;
use App\Models\Customer;
use App\Models\InstantSale;
use App\Models\OfferPackage;
use App\Models\Product;
use App\Models\SalesDailySession;
use App\Models\User;
use App\Services\InventoryCostingService;
use App\Services\SalesDailySessionService;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClosedDayInstantSaleDebtEditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshStoreSchema();
        Http::preventStrayRequests();
        $this->withoutMiddleware(RefreshSanctumTokenExpiry::class);
    }

    #[DataProvider('invoiceTypes')]
    public function test_unpaid_invoice_can_be_corrected_without_opening_a_drawer(bool $packageSale): void
    {
        [$sale, $product, $payload] = $this->createClosedInvoice($packageSale);
        $boxBalance = $sale->paymentBox->total;
        $boxLogs = BoxLog::count();

        $this->postJson('/api/edit/instant/sale', array_replace($payload, [
            'cost' => 75,
            'total_cost' => 75,
            'notes' => 'تصحيح سعر الفاتورة',
        ]))->assertOk()->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('instant_sales', [
            'id' => $sale->id,
            'sales_daily_session_id' => $sale->sales_daily_session_id,
            'payment_box_id' => $sale->payment_box_id,
            'payment_box_value' => 0,
            'total_cost' => 75,
            'notes' => 'تصحيح سعر الفاتورة',
        ]);
        $this->assertDatabaseHas('debt_transactions', [
            'source' => 'instant_sale',
            'source_id' => $sale->id,
            'customer_id' => $sale->buyer_id,
            'amount' => 75,
        ]);
        $this->assertDatabaseHas('instant_sale_revisions', [
            'instant_sale_id' => $sale->id,
            'action' => 'updated',
            'reason' => $payload['closed_day_edit_reason'],
        ]);
        $this->assertSame(9, (int) $product->fresh()->stock);
        $this->assertSame($boxBalance, $sale->paymentBox->fresh()->total);
        $this->assertSame($boxLogs, BoxLog::count());
        $this->assertSame(0, SalesDailySession::query()->where('status', 'open')->count());
    }

    public static function invoiceTypes(): array
    {
        return ['product' => [false], 'package' => [true]];
    }

    public function test_cash_settlement_still_requires_an_open_drawer(): void
    {
        [, , $payload] = $this->createClosedInvoice(false);

        $this->postJson('/api/edit/instant/sale', array_replace($payload, [
            'closed_day_edit_mode' => 'today_financial_settlement',
            'payment_box_value' => 50,
        ]))->assertOk()->assertJsonPath('status', 'error')
            ->assertJsonPath('errors.session.0', __('messages.sales_daily_no_session'));
    }

    private function createClosedInvoice(bool $packageSale): array
    {
        $user = User::factory()->create(['type' => 'admin']);
        Sanctum::actingAs($user);
        $session = app(SalesDailySessionService::class)->openSession($user, confirmOpeningVariance: true);
        $customer = Customer::create(['name' => 'Debt buyer', 'phone' => '0591234567']);
        $productId = (int) Product::withTrashed()->max('id') + 1;
        $product = Product::withoutEvents(fn () => Product::create([
            'id' => $productId,
            'product_code' => (string) $productId,
            'nameAr' => 'خوذة',
            'nameEng' => 'Helmet',
            'stock' => 0,
        ]));
        Product::withoutEvents(fn () => app(InventoryCostingService::class)->addOwnedStock(
            product: $product,
            quantity: 10,
            unitCost: 28,
            currency: 'شيكل',
            sourceType: 'opening_stock',
            sourceId: null,
        ));
        $payload = [
            'quantity' => 1,
            'cost' => 60,
            'total_cost' => 60,
            'discount' => 0,
            'type' => 'normal',
            'buyer_type' => 'customer',
            'buyer_id' => $customer->id,
            'payment_box_value' => 0,
        ];
        if ($packageSale) {
            $package = OfferPackage::create([
                'name' => 'عرض',
                'price' => 60,
                'package_quantity' => 10,
                'is_active' => true,
            ]);
            $package->items()->create(['product_id' => $product->id, 'quantity' => 1]);
            $payload['offer_package_id'] = $package->id;
        } else {
            $payload['product_id'] = $product->id;
        }

        $this->postJson('/api/create/instant/sale', $payload)
            ->assertOk()->assertJsonPath('status', 'success');
        $sale = InstantSale::query()->whereNull('parent_id')->sole();
        $session->update(['status' => 'closed', 'closed_at' => now()]);

        return [$sale, $product, $payload + [
            'instant_sale_id' => $sale->id,
            'closed_day_edit_mode' => 'administrative_correction',
            'closed_day_edit_reason' => 'تصحيح إداري لفاتورة دين',
        ]];
    }
}
