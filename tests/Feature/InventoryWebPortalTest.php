<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\User;
use App\Services\ProductStockService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryWebPortalTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private User $counter;

    private Product $product;

    private int $sessionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDisposableDatabase();
        Http::fake();
        $this->admin = User::factory()->create(['type' => 'inventory', 'inventory_web_role' => 'admin', 'is_blocked' => false]);
        $this->counter = User::factory()->create(['type' => 'inventory', 'inventory_web_role' => 'counter', 'is_blocked' => false]);
        $category = Category::create(['id' => 9000001, 'nameAr' => 'جرد اختبار', 'nameEng' => 'Inventory test', 'nameAbree' => 'Inventory test']);
        $this->product = Product::create(['id' => 9000001, 'nameAr' => 'خوذة اختبار', 'product_code' => '900001', 'stock' => 0, 'category_id' => $category->id, 'normailPrice' => 60, 'wholesalePrice' => 38]);
        $this->sessionId = DB::table('inventory_count_sessions')->insertGetId(['name' => 'جلسة اختبار', 'status' => 'open', 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function asAdmin(): static
    {
        return $this->actingAs($this->admin, 'inventory_web');
    }

    private function asCounter(): static
    {
        return $this->actingAs($this->counter, 'inventory_web');
    }

    private function submit(string $place, int $quantity, int $version = 0): void
    {
        $token = $this->getJson('/inventory/products/'.$this->product->id.'?session_id='.$this->sessionId)->assertOk()->json('stock_token');
        $this->postJson('/inventory/products/'.$this->product->id.'/count', ['session_id' => $this->sessionId, 'place' => $place, 'lines' => ['main' => $quantity], 'submit' => true, 'version' => $version, 'stock_token' => $token])->assertOk();
    }

    private function approvePayload(int $show = 3, int $warehouse = 7): array
    {
        $this->submit('showroom', $show);
        $this->submit('warehouse', $warehouse);

        return ['session_id' => $this->sessionId, 'request_key' => (string) Str::uuid(), 'reason' => 'جرد فعلي اختبار', 'increase_costs' => ['main' => 28]];
    }

    private function distribute(int $show = 3, int $warehouse = 7): void
    {
        $this->asAdmin();
        $payload = $this->approvePayload($show, $warehouse);
        $this->postJson('/inventory/products/'.$this->product->id.'/approve', $payload)->assertOk();
    }

    public function test_login_only_accepts_portal_accounts_and_revokes_blocked_sessions(): void
    {
        $this->postJson('/inventory/login', ['email' => $this->counter->email, 'password' => 'wrong'])->assertStatus(422);
        $this->postJson('/inventory/login', ['email' => $this->counter->email, 'password' => 'password'])->assertOk();
        $this->getJson('/inventory/sessions')->assertOk();
        $this->counter->forceFill(['is_blocked' => true])->save();
        auth('inventory_web')->forgetUser();
        $this->getJson('/inventory/sessions')->assertUnauthorized();
    }

    public function test_guest_and_non_portal_user_cannot_enter(): void
    {
        $this->getJson('/inventory/sessions')->assertUnauthorized();
        $ordinary = User::factory()->create(['type' => 'admin']);
        $this->actingAs($ordinary, 'inventory_web')->getJson('/inventory/products?session_id='.$this->sessionId)->assertUnauthorized();
    }

    public function test_counter_never_receives_cost_keys_or_admin_html(): void
    {
        $this->asCounter();
        $response = $this->getJson('/inventory/products/'.$this->product->id.'?session_id='.$this->sessionId)->assertOk();
        $text = $response->getContent();
        foreach (['"cost"', 'inventory_value', 'cost_version', 'reference_cost'] as $key) {
            $this->assertStringNotContainsString($key, $text);
        }
        $html = $this->get('/inventory')->assertOk()->getContent();
        $this->assertStringNotContainsString('id="new-cost"', $html);
        $this->assertStringNotContainsString('id="view-accounts"', $html);
        foreach (['/inventory/documents', '/inventory/users'] as $url) {
            $this->getJson($url)->assertForbidden();
        }
        $this->postJson('/inventory/products/'.$this->product->id.'/cost', [])->assertForbidden();
        $this->postJson('/inventory/products/'.$this->product->id.'/approve', [])->assertForbidden();
        $this->postJson('/inventory/products/'.$this->product->id.'/details', [])->assertForbidden();
        $this->postJson('/inventory/products/'.$this->product->id.'/image', [])->assertForbidden();
        $this->postJson('/inventory/transfers', [])->assertForbidden();
        $this->postJson('/inventory/users', [])->assertForbidden();
    }

    public function test_counter_counts_have_independent_places_and_do_not_change_stock(): void
    {
        $this->asCounter();
        $this->submit('showroom', 3);
        $this->getJson('/inventory/products?'.http_build_query(['session_id' => $this->sessionId, 'status' => 'pending']))->assertJsonPath('pending', 1);
        $this->getJson('/inventory/products?'.http_build_query(['session_id' => $this->sessionId, 'status' => 'done', 'scope' => 'showroom']))->assertJsonPath('done', 1);
        $this->getJson('/inventory/products?'.http_build_query(['session_id' => $this->sessionId, 'status' => 'done', 'scope' => 'both']))->assertJsonPath('done', 0);
        $this->submit('warehouse', 7);
        $this->getJson('/inventory/products?session_id='.$this->sessionId.'&status=done')->assertJsonPath('done', 1);
        $this->assertSame(0, (int) $this->product->fresh()->stock);
        $this->assertSame(0, DB::table('inventory_location_balances')->count());
        $this->getJson('/inventory/products/'.$this->product->id.'?session_id='.$this->sessionId)->assertJsonPath('history.showroom.place', 'showroom')->assertJsonPath('history.warehouse.place', 'warehouse');
    }

    public function test_blank_is_not_zero_and_stale_count_save_is_rejected(): void
    {
        $this->asCounter();
        $url = '/inventory/products/'.$this->product->id.'/count';
        $token = $this->getJson('/inventory/products/'.$this->product->id.'?session_id='.$this->sessionId)->json('stock_token');
        $data = ['session_id' => $this->sessionId, 'place' => 'showroom', 'lines' => ['main' => null], 'submit' => true, 'version' => 0, 'stock_token' => $token];
        $this->postJson($url, $data)->assertUnprocessable();
        $this->submit('showroom', 0);
        $this->postJson($url, array_replace($data, ['lines' => ['main' => 3]]))->assertStatus(409);
        $this->postJson($url, array_replace($data, ['lines' => ['main' => -1], 'version' => 1]))->assertUnprocessable();
    }

    public function test_approval_is_atomic_and_idempotent_and_preserves_engine_cost(): void
    {
        $this->asAdmin();
        $payload = $this->approvePayload();
        $url = '/inventory/products/'.$this->product->id.'/approve';
        $first = $this->postJson($url, $payload)->assertOk();
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('document_id', $first->json('document_id'));
        $this->assertSame(10, (int) $this->product->fresh()->stock);
        $balance = DB::table('inventory_location_balances')->first();
        $this->assertSame(3, $balance->showroom);
        $this->assertSame(7, $balance->warehouse);
        $this->assertEquals(280, DB::table('inventory_cost_layers')->selectRaw('SUM(remaining_quantity*unit_cost) AS total')->value('total'));
        $this->assertSame(1, DB::table('inventory_web_documents')->count());
    }

    public function test_activity_after_count_forces_recount_even_if_net_quantity_unchanged(): void
    {
        $this->asAdmin();
        $payload = $this->approvePayload();
        app(ProductStockService::class)->logMovement((int) $this->product->id, null, null, ProductStockMovement::TYPE_MANUAL_ADD, 1, 0, 1, 'test', null, null, (int) $this->admin->id);
        $this->postJson('/inventory/products/'.$this->product->id.'/approve', $payload)->assertStatus(409);
        $this->assertSame(0, (int) $this->product->fresh()->stock);
        $this->assertSame(0, DB::table('inventory_location_balances')->count());
    }

    public function test_transfers_do_not_change_aggregate_layers_or_sell_prices_and_cannot_duplicate(): void
    {
        $this->distribute();
        $beforeLayers = DB::table('inventory_cost_layers')->get()->toJson();
        $version = DB::table('inventory_location_balances')->value('version');
        $payload = ['request_key' => (string) Str::uuid(), 'from' => 'warehouse', 'date' => '2026-11-20', 'reason' => 'تزويد المعرض', 'lines' => [['product_id' => $this->product->id, 'key' => 'main', 'quantity' => 2, 'version' => $version]]];
        $first = $this->postJson('/inventory/transfers', $payload)->assertOk();
        $this->postJson('/inventory/transfers', $payload)->assertOk()->assertJsonPath('document_id', $first->json('document_id'));
        $balance = DB::table('inventory_location_balances')->first();
        $this->assertSame(5, $balance->showroom);
        $this->assertSame(5, $balance->warehouse);
        $this->assertSame(10, (int) $this->product->fresh()->stock);
        $this->assertEquals(60, $this->product->fresh()->normailPrice);
        $this->assertSame($beforeLayers, DB::table('inventory_cost_layers')->get()->toJson());
        $payload['request_key'] = (string) Str::uuid();
        $this->postJson('/inventory/transfers', $payload)->assertStatus(409);
    }

    public function test_transfer_overdraw_rolls_back_and_request_key_cannot_change_payload(): void
    {
        $this->distribute();
        $version = DB::table('inventory_location_balances')->value('version');
        $payload = ['request_key' => (string) Str::uuid(), 'from' => 'warehouse', 'date' => '2026-11-20', 'reason' => 'test', 'lines' => [['product_id' => $this->product->id, 'key' => 'main', 'quantity' => 8, 'version' => $version]]];
        $this->postJson('/inventory/transfers', $payload)->assertUnprocessable();
        $this->assertSame(7, DB::table('inventory_location_balances')->value('warehouse'));
        $payload['lines'][0]['quantity'] = 1;
        $this->postJson('/inventory/transfers', $payload)->assertOk();
        $payload['lines'][0]['quantity'] = 2;
        $this->postJson('/inventory/transfers', $payload)->assertStatus(409);
    }

    public function test_existing_stock_movement_updates_the_correct_location(): void
    {
        $this->distribute();
        $this->product->refresh();
        app(ProductStockService::class)->adjustStock($this->product, -2, ProductStockMovement::TYPE_SALE, null, 'test', null, null, (int) $this->admin->id);
        $this->assertSame(1, DB::table('inventory_location_balances')->value('showroom'));
        $this->assertSame(7, DB::table('inventory_location_balances')->value('warehouse'));
        app(ProductStockService::class)->adjustStock($this->product, 3, ProductStockMovement::TYPE_PURCHASE, null, 'test', null, null, (int) $this->admin->id);
        $this->assertSame(10, DB::table('inventory_location_balances')->value('warehouse'));
        $this->assertSame(11, (int) $this->product->fresh()->stock);
    }

    public function test_cost_revaluation_uses_existing_engine_and_keeps_quantity(): void
    {
        $this->distribute();
        $detail = $this->getJson('/inventory/products/'.$this->product->id.'?session_id='.$this->sessionId)->assertOk();
        $payload = ['request_key' => (string) Str::uuid(), 'key' => 'main', 'unit_cost' => 30, 'reason' => 'مراجعة مورد', 'cost_version' => $detail->json('cost_version'), 'expected_quantity' => 10];
        $this->postJson('/inventory/products/'.$this->product->id.'/cost', $payload)->assertOk();
        $this->assertSame(10, (int) $this->product->fresh()->stock);
        $this->assertSame(3, DB::table('inventory_location_balances')->value('showroom'));
        $this->assertEquals(30, DB::table('inventory_cost_layers')->value('unit_cost'));
        $payload['request_key'] = (string) Str::uuid();
        $this->postJson('/inventory/products/'.$this->product->id.'/cost', $payload)->assertStatus(409);
    }

    public function test_admin_can_add_variant_and_new_image_preserves_previous_media(): void
    {
        $this->asAdmin();
        $this->postJson('/inventory/products/'.$this->product->id.'/variants', ['size' => 'M', 'color' => 'أحمر', 'retail' => 60, 'wholesale' => 38])->assertOk();
        $this->product->refresh();
        $this->assertSame(0, (int) $this->product->stock);
        $this->assertDatabaseHas('size_colors', ['colorAr' => 'أحمر', 'stock' => 0]);
        Storage::fake('public');
        $this->post('/inventory/products/'.$this->product->id.'/image', ['key' => 'product', 'image' => UploadedFile::fake()->image('helmet.jpg')], ['Accept' => 'application/json'])->assertOk();
        $this->assertNotEmpty($this->product->fresh()->inventory_web_image);
    }

    public function test_admin_can_create_counter_without_exposing_password_hash_or_promoting_app_role(): void
    {
        $this->asAdmin();
        $this->postJson('/inventory/users', ['name' => 'موظف جديد', 'email' => 'counter-test@example.test', 'password' => 'Long-Test-Password-123', 'role' => 'counter', 'blocked' => false])->assertOk();
        $u = User::where('email', 'counter-test@example.test')->firstOrFail();
        $this->assertSame('inventory', $u->type);
        $this->assertTrue(Hash::check('Long-Test-Password-123', $u->password));
        $this->assertStringNotContainsString('password', $this->getJson('/inventory/users')->assertOk()->getContent());
    }

    public function test_stock_change_before_first_save_invalidates_the_browser_snapshot(): void
    {
        $this->asCounter();
        $token = $this->getJson('/inventory/products/'.$this->product->id.'?session_id='.$this->sessionId)->json('stock_token');
        app(ProductStockService::class)->logMovement((int) $this->product->id, null, null, ProductStockMovement::TYPE_MANUAL_ADD, 1, 0, 1, 'test', null, null, (int) $this->admin->id);
        $this->postJson('/inventory/products/'.$this->product->id.'/count', ['session_id' => $this->sessionId, 'place' => 'showroom', 'lines' => ['main' => 1], 'submit' => true, 'version' => 0, 'stock_token' => $token])->assertStatus(409);
        $this->assertSame(0, DB::table('inventory_web_counts')->count());
    }

    public function test_history_survives_a_later_draft_and_foreign_variants_are_rejected(): void
    {
        $this->asCounter();
        $this->submit('showroom', 0);
        $token = $this->getJson('/inventory/products/'.$this->product->id.'?session_id='.$this->sessionId)->json('stock_token');
        $this->postJson('/inventory/products/'.$this->product->id.'/count', ['session_id' => $this->sessionId, 'place' => 'showroom', 'lines' => ['main' => null], 'submit' => false, 'version' => 1, 'stock_token' => $token])->assertOk();
        $this->getJson('/inventory/products/'.$this->product->id.'?session_id='.$this->sessionId)->assertJsonPath('history.showroom.place', 'showroom');
        $this->postJson('/inventory/products/'.$this->product->id.'/count', ['session_id' => $this->sessionId, 'place' => 'warehouse', 'lines' => ['999' => 1], 'submit' => true, 'version' => 0, 'stock_token' => $token])->assertUnprocessable();
    }

    public function test_variant_stock_is_distributed_independently_and_zero_stock_can_record_reference_cost(): void
    {
        $this->asAdmin();
        $id = $this->postJson('/inventory/products/'.$this->product->id.'/variants', ['size' => 'M', 'color' => 'أحمر', 'retail' => 60, 'wholesale' => 38])->assertOk()->json('id');
        $detail = $this->getJson('/inventory/products/'.$this->product->id.'?session_id='.$this->sessionId)->assertOk();
        $token = $detail->json('stock_token');
        foreach (['showroom' => 2, 'warehouse' => 3] as $place => $quantity) {
            $this->postJson('/inventory/products/'.$this->product->id.'/count', ['session_id' => $this->sessionId, 'place' => $place, 'lines' => [(string) $id => $quantity], 'submit' => true, 'version' => 0, 'stock_token' => $token])->assertOk();
        }
        $this->postJson('/inventory/products/'.$this->product->id.'/approve', ['session_id' => $this->sessionId, 'request_key' => (string) Str::uuid(), 'reason' => 'جرد لون', 'increase_costs' => [(string) $id => 28]])->assertOk();
        $this->assertSame(5, (int) $this->product->fresh()->stock);
        $this->assertDatabaseHas('inventory_location_balances', ['size_color_id' => $id, 'showroom' => 2, 'warehouse' => 3]);
        $empty = Product::create(['id' => 9000002, 'nameAr' => 'منتج صفر', 'product_code' => '900002', 'stock' => 0]);
        $detail = $this->getJson('/inventory/products/'.$empty->id.'?session_id='.$this->sessionId)->assertOk();
        $this->postJson('/inventory/products/'.$empty->id.'/cost',['request_key' => (string) Str::uuid(), 'key' => 'main', 'unit_cost' => 10, 'reason' => 'مرجع مورد', 'cost_version' => 0, 'expected_quantity' => 0])->assertOk();
        $this->assertSame(0,(int) $empty->fresh()->stock);
        $this->assertDatabaseHas('inventory_adjustments',['product_id' => $empty->id, 'adjustment_type' => 'cost_reference', 'new_unit_cost' => 10]);
    }
}
