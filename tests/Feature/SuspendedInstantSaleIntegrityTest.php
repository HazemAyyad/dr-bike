<?php

namespace Tests\Feature;

use App\Http\Controllers\API\InstantSales;
use App\Http\Middleware\RefreshSanctumTokenExpiry;
use App\Models\Product;
use App\Models\SuspendedInstantSale;
use App\Models\SuspendedInstantSaleRevision;
use App\Models\User;
use App\Services\AdminNotificationService;
use App\Services\SalesDailySessionService;
use App\Services\SuspendedInstantSaleHistoryService;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class SuspendedInstantSaleIntegrityTest extends TestCase
{
    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshStoreSchema();
        Http::preventStrayRequests();
        $this->withoutMiddleware(RefreshSanctumTokenExpiry::class);
        $this->admin = User::factory()->create(['type' => 'admin']);
        Sanctum::actingAs($this->admin);
        $this->mock(AdminNotificationService::class, function ($mock) {
            $mock->shouldReceive('create')->andReturn(new \App\Models\AdminNotification);
        });
        $id = (int) (Product::withTrashed()->max('id') ?? 0) + 1;
        $this->product = Product::withoutEvents(fn () => Product::create([
            'id' => $id, 'product_code' => (string) $id, 'nameAr' => 'صنف أصلي',
            'nameEng' => 'Original product', 'stock' => 0,
        ]));
    }

    public function test_all_111_original_lines_and_names_survive_an_intentional_replacement(): void
    {
        $payload = $this->payload();
        $payload['other_products'] = array_fill(0, 110, [
            'product_id' => $this->product->id, 'quantity' => 2, 'cost' => 15,
        ]);
        $payload['total_cost'] = 25507.5;
        $original = $this->suspend($payload);
        $replacement = $this->payload('زبون جديد');
        $replacement['total_cost'] = $replacement['cost'] = 175;
        $this->postJson('/api/suspended/instant/sale', [
            'suspended_instant_sale_id' => $original['id'], 'current_step' => 'checkout',
            'payload' => $replacement, 'expected_payload_hash' => $original['payload_hash'],
            'confirm_buyer_change' => true,
        ])->assertJsonPath('status', 'success');
        Product::withoutEvents(fn () => $this->product->update(['nameAr' => 'اسم تغيّر لاحقاً']));

        $history = $this->getJson('/api/suspended/instant/sale/history?suspended_instant_sale_id='.$original['id'])
            ->assertOk()->assertJsonPath('status', 'success')->json('history');
        $this->assertCount(2, $history);
        $this->assertCount(111, $history[0]['after_snapshot']['lines']);
        $this->assertCount(111, $history[1]['before_snapshot']['lines']);
        $this->assertSame('صنف أصلي', $history[0]['after_snapshot']['lines'][0]['name']);
        $this->assertEquals(25507.5, $history[1]['before_snapshot']['total_cost']);
        $this->assertSame('خليل شحتوت', $history[1]['before_snapshot']['payload']['buyer_name']);
        $this->assertSame('زبون جديد', $history[1]['after_snapshot']['payload']['buyer_name']);
        $this->assertSame($this->admin->name, $history[1]['actor_name']);
    }

    public function test_a_legacy_client_cannot_replace_a_different_suspended_cart(): void
    {
        $original = $this->suspend($this->payload());
        $this->postJson('/api/suspended/instant/sale/complete', [
            'suspended_instant_sale_id' => $original['id'],
            'payload' => $this->payload('السيلاوي'),
        ])->assertJsonPath('status', 'error')->assertJsonStructure(['errors' => ['expected_payload_hash']]);
        $this->assertOriginalUnchanged($original['id']);
    }

    public function test_a_buyer_change_requires_explicit_confirmation_even_with_a_current_hash(): void
    {
        $original = $this->suspend($this->payload());
        $this->postJson('/api/suspended/instant/sale/complete', [
            'suspended_instant_sale_id' => $original['id'],
            'payload' => $this->payload('السيلاوي'),
            'expected_payload_hash' => $original['payload_hash'],
        ])->assertJsonPath('status', 'error')->assertJsonStructure(['errors' => ['confirm_buyer_change']]);
        $this->assertOriginalUnchanged($original['id']);
    }

    public function test_a_legacy_client_cannot_silently_replace_items_for_the_same_buyer(): void
    {
        $original = $this->suspend($this->payload());
        $changed = $this->payload();
        $changed['quantity'] = 50;
        $this->postJson('/api/suspended/instant/sale', [
            'suspended_instant_sale_id' => $original['id'], 'current_step' => 'checkout',
            'payload' => $changed,
        ])->assertJsonPath('status', 'error')->assertJsonStructure(['errors' => ['expected_payload_hash']]);
        $this->assertEquals(1, SuspendedInstantSale::findOrFail($original['id'])->payload['quantity']);
    }

    public function test_an_unchanged_legacy_save_remains_compatible(): void
    {
        $original = $this->suspend($this->payload());
        $this->postJson('/api/suspended/instant/sale', [
            'suspended_instant_sale_id' => $original['id'], 'current_step' => 'checkout',
            'payload' => $this->payload(),
        ])->assertJsonPath('status', 'success');
    }

    public function test_package_contents_are_archived_before_the_package_definition_changes(): void
    {
        $package = \App\Models\OfferPackage::create([
            'name' => 'باكيج أصلي', 'price' => 60, 'package_quantity' => 10, 'is_active' => true,
        ]);
        $item = $package->items()->create(['product_id' => $this->product->id, 'quantity' => 3]);
        $payload = $this->payload();
        unset($payload['product_id']);
        $payload['offer_package_id'] = $package->id;
        $payload['quantity'] = 2;
        $original = $this->suspend($payload);
        $item->update(['quantity' => 99]);
        $line = SuspendedInstantSaleRevision::where('suspended_instant_sale_id', $original['id'])
            ->firstOrFail()->after_snapshot['lines'][0];
        $this->assertSame('باكيج أصلي', $line['name']);
        $this->assertSame('صنف أصلي', $line['package_items'][0]['name']);
        $this->assertEquals(6, $line['package_items'][0]['quantity']);
    }

    public function test_a_stale_editor_cannot_overwrite_a_more_recent_save(): void
    {
        $original = $this->suspend($this->payload());
        $changed = $this->payload();
        $changed['quantity'] = 2;
        $changed['total_cost'] = 30;
        $this->postJson('/api/suspended/instant/sale', [
            'suspended_instant_sale_id' => $original['id'], 'current_step' => 'checkout',
            'payload' => $changed, 'expected_payload_hash' => $original['payload_hash'],
        ])->assertJsonPath('status', 'success');
        $this->postJson('/api/suspended/instant/sale', [
            'suspended_instant_sale_id' => $original['id'], 'current_step' => 'checkout',
            'payload' => $this->payload(), 'expected_payload_hash' => $original['payload_hash'],
        ])->assertJsonPath('status', 'error')->assertJsonStructure(['errors' => ['expected_payload_hash']]);
        $this->assertEquals(2, SuspendedInstantSale::findOrFail($original['id'])->payload['quantity']);
        $this->assertSame(2, SuspendedInstantSaleRevision::count());
    }

    public function test_failed_completion_rolls_back_the_payload_and_archive(): void
    {
        $original = $this->suspend($this->payload());
        $session = app(SalesDailySessionService::class)->openSession($this->admin, confirmOpeningVariance: true);
        $this->mock(SalesDailySessionService::class, function ($mock) use ($session) {
            $mock->shouldReceive('assertCanCreateSaleToday')->once()->andReturn($session);
        });
        $this->mock(InstantSales::class, function ($mock) {
            $mock->shouldReceive('store')->once()->andReturn(response()->json([
                'status' => 'error', 'message' => 'Stock unavailable',
            ]));
        });
        $changed = $this->payload();
        $changed['quantity'] = 2;
        $this->postJson('/api/suspended/instant/sale/complete', [
            'suspended_instant_sale_id' => $original['id'], 'payload' => $changed,
            'expected_payload_hash' => $original['payload_hash'],
        ])->assertJsonPath('status', 'error');
        $this->assertOriginalUnchanged($original['id']);
        $this->assertEquals(1, SuspendedInstantSale::findOrFail($original['id'])->payload['quantity']);
    }

    public function test_successful_completion_preserves_the_saved_cart_and_links_the_new_sale(): void
    {
        app(SalesDailySessionService::class)->openSession($this->admin, confirmOpeningVariance: true);
        Product::withoutEvents(fn () => app(\App\Services\InventoryCostingService::class)->addOwnedStock(
            product: $this->product, quantity: 10, unitCost: 5, currency: 'شيكل',
            sourceType: 'opening_stock', sourceId: null,
        ));
        $payload = $this->payload();
        $payload['buyer_id'] = \App\Models\Customer::create(['name' => 'خليل شحتوت'])->id;
        $payload['payment_box_value'] = 0;
        $original = $this->suspend($payload);
        $changed = $payload;
        $changed['quantity'] = 2;
        $changed['total_cost'] = 30;
        $response = $this->postJson('/api/suspended/instant/sale/complete', [
            'suspended_instant_sale_id' => $original['id'], 'payload' => $changed,
            'expected_payload_hash' => $original['payload_hash'],
        ]);
        $this->assertSame('success', $response->json('status'), $response->getContent());
        $saleId = $response->json('instant_sale_id');
        $record = SuspendedInstantSale::findOrFail($original['id']);
        $this->assertSame('completed', $record->status);
        $this->assertEquals($saleId, $record->completed_instant_sale_id);
        $revision = SuspendedInstantSaleRevision::orderByDesc('id')->firstOrFail();
        $this->assertSame('completed', $revision->action);
        $this->assertEquals(1, $revision->before_snapshot['lines'][0]['quantity']);
        $this->assertEquals(2, $revision->after_snapshot['lines'][0]['quantity']);
        $this->assertSame(8, (int) $this->product->fresh()->stock);
        $this->assertDatabaseHas('debt_transactions', [
            'source' => 'instant_sale', 'source_id' => $saleId, 'amount' => 30,
        ]);
    }

    public function test_audit_failure_prevents_committing_an_update(): void
    {
        $original = $this->suspend($this->payload());
        $history = Mockery::mock(SuspendedInstantSaleHistoryService::class)->makePartial();
        $history->shouldReceive('record')->once()->andThrow(new \RuntimeException('Archive unavailable'));
        $this->app->instance(SuspendedInstantSaleHistoryService::class, $history);
        $changed = $this->payload();
        $changed['quantity'] = 3;
        $this->postJson('/api/suspended/instant/sale', [
            'suspended_instant_sale_id' => $original['id'], 'current_step' => 'checkout',
            'payload' => $changed, 'expected_payload_hash' => $original['payload_hash'],
        ])->assertJsonPath('status', 'error');
        $this->assertEquals(1, SuspendedInstantSale::findOrFail($original['id'])->payload['quantity']);
        $this->assertSame(1, SuspendedInstantSaleRevision::count());
    }

    public function test_notes_and_cancellation_are_archived_without_erasing_original_lines(): void
    {
        $original = $this->suspend($this->payload());
        $this->postJson('/api/suspended/instant/sale/note', [
            'suspended_instant_sale_id' => $original['id'], 'note' => 'ملاحظة محفوظة',
        ])->assertJsonPath('status', 'success');
        $this->postJson('/api/suspended/instant/sale/cancel', [
            'suspended_instant_sale_id' => $original['id'],
        ])->assertJsonPath('status', 'success');
        $revisions = SuspendedInstantSaleRevision::orderBy('id')->get();
        $this->assertSame(['created', 'note_added', 'cancelled'], $revisions->pluck('action')->all());
        $this->assertSame('cancelled', $revisions->last()->after_snapshot['status']);
        $this->assertSame('صنف أصلي', $revisions->last()->before_snapshot['lines'][0]['name']);
    }

    public function test_other_users_cannot_read_private_auto_saved_history(): void
    {
        $original = $this->suspend($this->payload(), 'auto');
        Sanctum::actingAs(User::factory()->create(['type' => 'admin']));
        $this->getJson('/api/suspended/instant/sale/history?suspended_instant_sale_id='.$original['id'])
            ->assertUnprocessable()->assertJsonMissingPath('history');
    }

    private function payload(string $buyer = 'خليل شحتوت'): array
    {
        return ['product_id' => $this->product->id, 'quantity' => 1, 'cost' => 15,
            'total_cost' => 15, 'buyer_type' => 'customer', 'buyer_name' => $buyer];
    }

    private function suspend(array $payload, string $saveType = 'manual'): array
    {
        return $this->postJson('/api/suspended/instant/sale', [
            'current_step' => 'checkout', 'payload' => $payload, 'save_type' => $saveType,
        ])->assertOk()->assertJsonPath('status', 'success')->json('suspended_instant_sale');
    }

    private function assertOriginalUnchanged(int $id): void
    {
        $record = SuspendedInstantSale::findOrFail($id);
        $this->assertSame('suspended', $record->status);
        $this->assertSame('خليل شحتوت', $record->payload['buyer_name']);
        $this->assertSame(1, SuspendedInstantSaleRevision::count());
        $this->assertDatabaseCount('instant_sales', 0);
    }
}
