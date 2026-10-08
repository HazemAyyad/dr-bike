<?php

namespace Tests\Feature\OnlineStore;

use App\Models\NormalImageProduct;
use App\Models\OnlineStore\OnlineStoreListing;
use App\Models\StoreSection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\OnlineStoreFixtureFactory;
use Tests\TestCase;

class StorefrontCompatibilityAuthorityTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (! filter_var(env('ONLINE_STORE_DUMP_DERIVED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            Artisan::call('migrate:fresh', ['--force' => true]);
        }
    }

    public function test_categories_use_only_active_online_store_hierarchy_in_deterministic_order(): void
    {
        StoreSection::query()->forceCreate(['id' => 1980000001, 'name' => 'Warehouse A', 'sort_order' => 0, 'is_active' => true]);
        $second = $this->category('Second', 2);
        $first = $this->category('First', 1);
        DB::table('online_store_categories')->where('id', $first)->update([
            'image_path' => 'public/OnlineStore/Content/first.jpg',
        ]);
        $child = $this->category('Child', 0, $first);
        $this->category('Inactive', 0, null, false);

        $main = $this->postJson('/MainCategorys/GetAllShowMainCategories')->assertOk();
        $main->assertJsonCount(2, 'rows')
            ->assertJsonPath('rows.0.id', $first)
            ->assertJsonPath('rows.0.imageUrl', 'OnlineStore/Content/first.jpg')
            ->assertJsonPath('rows.0.supCategories.0.id', $child)
            ->assertJsonPath('rows.1.id', $second);
        $this->assertNotContains('Warehouse A', collect($main->json('rows'))->pluck('nameAr'));

        $this->postJson('/SupCategorys/GetAllShowSupCategories', ['mainCategoryId' => $first])
            ->assertOk()->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.id', $child)
            ->assertJsonPath('rows.0.mainCategoryId', $first);
    }

    public function test_product_reads_require_direct_online_store_membership_and_eligible_listing(): void
    {
        $category = $this->category('Catalog', 0);
        $otherCategory = $this->category('Other', 1);
        $visible = $this->listing('Visible searchable', 'published', 'complete', $category);
        $draft = $this->listing('Draft searchable', 'draft', 'complete', $category);
        $hidden = $this->listing('Hidden searchable', 'hidden', 'complete', $category);
        $incomplete = $this->listing('Incomplete searchable', 'published', 'incomplete', $category, false);
        $other = $this->listing('Other searchable', 'published', 'complete', $otherCategory);
        $visible->product->forceFill(['store_section_id' => StoreSection::query()->forceCreate([
            'id' => 1980000002, 'name' => 'Physical only', 'sort_order' => 0, 'is_active' => true,
        ])->id])->save();

        $categoryRows = $this->postJson('/Items/GetAllItemsShowByMainCategory', ['MainCategory' => $category])
            ->assertOk()->json('rows');
        $this->assertSame([$visible->id], collect($categoryRows)->pluck('listingId')->all());
        $this->assertNotContains($other->id, collect($categoryRows)->pluck('listingId'));
        $this->assertNotContains($draft->id, collect($categoryRows)->pluck('listingId'));
        $this->assertNotContains($hidden->id, collect($categoryRows)->pluck('listingId'));
        $this->assertNotContains($incomplete->id, collect($categoryRows)->pluck('listingId'));

        $search = $this->postJson('/Items/GetAllItemByName', ['Name' => 'searchable'])->assertOk();
        $this->assertEqualsCanonicalizing([$visible->id, $other->id], collect($search->json('rows'))->pluck('listingId')->all());
        $search->assertJsonFragment(['id' => $visible->product_id, 'productId' => $visible->product_id, 'listingId' => $visible->id]);
        $this->assertNotSame($visible->product_id, $visible->id);

        $this->postJson('/Items/GetItemById', ['itemId' => $draft->product_id])->assertNotFound();
        $this->postJson('/Items/GetItemById', ['itemId' => $visible->product_id])->assertOk()->assertJsonPath('listingId', $visible->id);
        $this->assertSame(1, (int) $visible->fresh()->view_count);
    }

    public function test_soft_deleted_product_and_unpresented_media_are_never_exposed(): void
    {
        $category = $this->category('Catalog', 0);
        $listing = $this->listing('Media product', 'published', 'complete', $category, true, true);
        $payload = $this->postJson('/Items/GetItemById', ['itemId' => $listing->product_id])->assertOk();
        $payload->assertJsonCount(2, 'storefrontMedia')
            ->assertJsonPath('storefrontMedia.0.path', 'main.jpg')
            ->assertJsonPath('storefrontMedia.0.is_main', true)
            ->assertJsonPath('storefrontMedia.1.path', 'second.jpg');
        $this->assertNotContains('hidden.jpg', collect($payload->json('storefrontMedia'))->pluck('path'));
        $this->assertSame(['main.jpg', 'second.jpg'], collect($payload->json('storefrontMedia'))->pluck('path')->all());

        $listing->product->delete();
        $this->postJson('/Items/GetItemById', ['itemId' => $listing->product_id])->assertNotFound();
    }

    public function test_catalog_price_filters_and_sorting_are_applied_by_the_server(): void
    {
        $category = $this->category('Filtered Catalog', 0);
        $low = $this->listing('Low price', 'published', 'complete', $category);
        $high = $this->listing('High price', 'published', 'complete', $category);
        $low->product->forceFill(['normailPrice' => 50])->save();
        $high->product->forceFill(['normailPrice' => 150])->save();

        $filtered = $this->postJson(
            '/Items/GetAllItemsShowByMainCategory?MainCategory='.$category.'&minPrice=100&maxPrice=200&sort=price_desc'
        )->assertOk();

        $filtered->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.listingId', $high->id)
            ->assertJsonPath('rows.0.normailPrice', 150);

        $sorted = $this->postJson(
            '/Items/GetAllItemsShowByMainCategory?MainCategory='.$category.'&sort=price_asc'
        )->assertOk();
        $this->assertSame([$low->id, $high->id], collect($sorted->json('rows'))->pluck('listingId')->all());
    }

    private function category(string $name, int $sortOrder, ?int $parentId = null, bool $active = true): int
    {
        return (int) DB::table('online_store_categories')->insertGetId([
            'name_translations' => json_encode(['ar' => $name, 'en' => $name]),
            'description_translations' => json_encode(['ar' => "$name description"]),
            'image_path' => "$name.jpg", 'parent_id' => $parentId, 'is_active' => $active,
            'show_on_home' => false, 'sort_order' => $sortOrder, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function listing(string $name, string $status, string $readiness, int $categoryId, bool $validReadiness = true, bool $multipleMedia = false): OnlineStoreListing
    {
        $product = OnlineStoreFixtureFactory::createProduct(['nameAr' => $name, 'nameEng' => $name, 'descriptionEng' => 'Description']);
        $listing = OnlineStoreListing::query()->forceCreate([
            'id' => $product->id + 10000000, 'product_id' => $product->id, 'status' => $status,
            'readiness_state' => $readiness, 'name_translations' => ['en' => $name],
        ]);
        DB::table('online_store_category_listing')->insert([
            'online_store_category_id' => $categoryId, 'online_store_listing_id' => $listing->id,
            'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($validReadiness) {
            $this->media($listing, 'main.jpg', true, true, 0);
            if ($multipleMedia) {
                $this->media($listing, 'hidden.jpg', false, false, 1);
                $this->media($listing, 'second.jpg', false, true, 2);
            }
        }

        return $listing->fresh('product');
    }

    private function media(OnlineStoreListing $listing, string $path, bool $main, bool $visible, int $sort): void
    {
        $sourceId = $listing->id + 100 + $sort;
        NormalImageProduct::query()->forceCreate(['id' => $sourceId, 'itemId' => $listing->product_id, 'imageUrl' => $path]);
        DB::table('online_store_media_presentations')->insert([
            'online_store_listing_id' => $listing->id, 'source_type' => 'normal_image', 'source_id' => $sourceId,
            'is_main' => $main, 'is_visible' => $visible, 'sort_order' => $sort,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
