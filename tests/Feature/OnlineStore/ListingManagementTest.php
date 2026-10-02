<?php

namespace Tests\Feature\OnlineStore;

use App\Models\EmployeeDetail;
use App\Models\EmployeePermission;
use App\Models\NormalImageProduct;
use App\Models\OnlineStore\OnlineStoreListing;
use App\Models\Permission;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\OnlineStoreFixtureFactory;
use Tests\TestCase;

class ListingManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (filter_var(env('ONLINE_STORE_DUMP_DERIVED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Listing tests cannot alter a restored dump-derived schema.');
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    public function test_exact_permission_is_required_and_inaccessible_listing_is_not_disclosed(): void
    {
        $product = OnlineStoreFixtureFactory::createProduct();
        $admin = OnlineStoreFixtureFactory::createAuthenticatedAdminActor();
        $listing = $this->postJson('/api/online-store/listings', ['product_id' => $product->id])->assertCreated()->json('data');

        $employee = OnlineStoreFixtureFactory::createStoreActor(['type' => 'employee']);
        Sanctum::actingAs($employee);
        $this->postJson('/api/online-store/listings', ['product_id' => $product->id])->assertForbidden();
        $this->getJson('/api/online-store/listings/'.$listing['id'])->assertNotFound();

        $detail = EmployeeDetail::query()->forceCreate(['user_id' => $employee->id]);
        $permission = Permission::query()->firstOrCreate(['name_en' => 'Online Store Products Manage'], ['name' => 'Online Store Products Manage']);
        EmployeePermission::query()->create(['employee_id' => $detail->id, 'permission_id' => $permission->id]);
        $this->getJson('/api/online-store/listings/'.$listing['id'])->assertOk();
    }

    public function test_listing_is_unique_per_product_and_product_price_and_stock_cannot_be_mutated(): void
    {
        $product = OnlineStoreFixtureFactory::createProduct(['normailPrice' => 120, 'stock' => 7]);
        OnlineStoreFixtureFactory::createAuthenticatedAdminActor();
        $listing = $this->postJson('/api/online-store/listings', ['product_id' => $product->id])->assertCreated()->json('data');
        $this->postJson('/api/online-store/listings', ['product_id' => $product->id])->assertConflict();
        $this->patchJson('/api/online-store/listings/'.$listing['id'], ['normailPrice' => 1, 'stock' => 0])->assertUnprocessable();
        $this->assertSame(120.0, (float) $product->fresh()->normailPrice);
        $this->assertSame(7, (int) $product->fresh()->stock);
    }

    public function test_readiness_and_lifecycle_use_same_row_and_write_audit_events(): void
    {
        $product = OnlineStoreFixtureFactory::createProduct(['descriptionEng' => 'Complete description']);
        $admin = OnlineStoreFixtureFactory::createAuthenticatedAdminActor();
        $listingData = $this->postJson('/api/online-store/listings', ['product_id' => $product->id])->assertCreated()->json('data');
        $listing = OnlineStoreListing::findOrFail($listingData['id']);
        $image = NormalImageProduct::query()->forceCreate(['id' => 1800000001, 'itemId' => $product->id, 'imageUrl' => 'fixtures/product.jpg']);
        DB::table('online_store_categories')->insert(['id' => 1800000002, 'name_translations' => json_encode(['en' => 'Category']), 'is_active' => true, 'show_on_home' => false, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('online_store_category_listing')->insert(['online_store_category_id' => 1800000002, 'online_store_listing_id' => $listing->id, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('online_store_media_presentations')->insert(['online_store_listing_id' => $listing->id, 'source_type' => 'normal_image', 'source_id' => $image->id, 'is_main' => true, 'is_visible' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $this->postJson("/api/online-store/listings/{$listing->id}/transition", ['status' => 'ready'])->assertOk()->assertJsonPath('data.status', 'ready');
        $this->postJson("/api/online-store/listings/{$listing->id}/transition", ['status' => 'published'])->assertOk()->assertJsonPath('data.status', 'published');
        $this->postJson("/api/online-store/listings/{$listing->id}/transition", ['status' => 'hidden'])->assertOk()->assertJsonPath('data.status', 'hidden');
        $this->assertSame($listing->id, OnlineStoreListing::where('product_id', $product->id)->sole()->id);
        $this->assertDatabaseCount('online_store_listings', 1);
        $this->assertDatabaseHas('online_store_audit_events', ['actor_user_id' => $admin->id, 'entity_type' => 'listing', 'entity_id' => $listing->id, 'action' => 'published']);

        DB::table('online_store_category_listing')->where('online_store_listing_id', $listing->id)->delete();
        $this->patchJson("/api/online-store/listings/{$listing->id}", ['is_featured' => true])
            ->assertOk()->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.readiness_state', 'incomplete');
    }

    public function test_incomplete_listing_cannot_be_published_and_draft_may_have_no_media(): void
    {
        $product = OnlineStoreFixtureFactory::createProduct();
        OnlineStoreFixtureFactory::createAuthenticatedAdminActor();
        $listing = $this->postJson('/api/online-store/listings', ['product_id' => $product->id])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data');
        $this->assertContains('missing_main_media', $listing['readiness_issues']);
        $this->postJson('/api/online-store/listings/'.$listing['id'].'/transition', ['status' => 'published'])->assertUnprocessable();
        $this->getJson('/api/online-store/products/'.$product->id.'/store-readiness')
            ->assertOk()->assertJsonPath('data.product_id', $product->id)
            ->assertJsonPath('data.availability.stock', 5);
    }

    public function test_listing_with_soft_deleted_product_serializes_as_incomplete_without_restoring_product(): void
    {
        $product = OnlineStoreFixtureFactory::createProduct();
        OnlineStoreFixtureFactory::createAuthenticatedAdminActor();
        $listing = $this->postJson('/api/online-store/listings', ['product_id' => $product->id])
            ->assertCreated()->json('data');

        $product->delete();

        $this->getJson('/api/online-store/listings/'.$listing['id'])
            ->assertOk()
            ->assertJsonPath('data.readiness_state', 'incomplete')
            ->assertJsonPath('data.readiness_issues.0', 'missing_product')
            ->assertJsonPath('data.product_archived', true)
            ->assertJsonPath('data.base_prices.retail', null)
            ->assertJsonPath('data.availability.purchasable', false);

        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }
}
