<?php

namespace Tests\Feature\OnlineStore;

use App\Models\OnlineStore\OnlineStoreListing;
use App\Services\OnlineStore\PromotionService;
use App\Services\OnlineStore\StorePricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\OnlineStoreFixtureFactory;
use Tests\TestCase;

class StoreCatalogPaginationTest extends TestCase
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

    private function seedCatalog(int $count): array
    {
        $now = now();
        DB::table('online_store_categories')->insert(['id' => 81001, 'name_translations' => json_encode(['ar' => 'Test']), 'is_active' => true, 'sort_order' => 0]);
        $productIds = $listingIds = [];
        foreach (array_chunk(range(1, $count), 500) as $chunk) {
            $products = $listings = $members = $media = [];
            foreach ($chunk as $index) {
                $id = 800000000 + $index;
                $productIds[] = $id;
                $listingIds[] = $id;
                $products[] = ['id' => $id, 'product_code' => str_pad((string) $index, 6, '0', STR_PAD_LEFT), 'nameAr' => 'Catalog '.$index,
                    'nameEng' => 'Catalog '.$index, 'descriptionEng' => 'Description', 'normailPrice' => $index,
                    'wholesalePrice' => $index, 'stock' => $index % 3 === 0 ? 0 : 10, 'created_at' => $now, 'updated_at' => $now];
                $listings[] = ['id' => $id, 'product_id' => $id, 'status' => 'published', 'readiness_state' => 'complete',
                    'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now];
                $members[] = ['online_store_listing_id' => $id, 'online_store_category_id' => 81001, 'sort_order' => 0];
                $media[] = ['online_store_listing_id' => $id, 'source_type' => 'store_specific', 'store_media_path' => 'fixtures/product.jpg', 'is_main' => true, 'is_visible' => true, 'sort_order' => 0];
            }
            DB::table('products')->insert($products);
            DB::table('online_store_listings')->insert($listings);
            DB::table('online_store_category_listing')->insert($members);
            DB::table('online_store_media_presentations')->insert($media);
        }

        return compact('productIds', 'listingIds');
    }

    public function test_pages_have_stable_unique_results_and_filters_precede_pagination(): void
    {
        $this->seedCatalog(51);
        $first = $this->getJson('/OnlineStore/Catalog')->assertOk()->assertJsonCount(24, 'rows')
            ->assertJsonPath('paginationInfo.totalRowsCount', 51)->assertJsonPath('paginationInfo.hasMore', true)->json('rows');
        $second = $this->getJson('/OnlineStore/Catalog?page=2')->assertOk()->assertJsonCount(24, 'rows')->json('rows');
        $third = $this->getJson('/OnlineStore/Catalog?page=3')->assertOk()->assertJsonCount(3, 'rows')
            ->assertJsonPath('paginationInfo.hasMore', false)->json('rows');
        $this->assertCount(51, array_unique(array_column([...$first, ...$second, ...$third], 'id')));
        $this->getJson('/OnlineStore/Catalog?minPrice=40&maxPrice=50&sort=price_desc&per_page=4')
            ->assertOk()->assertJsonCount(4, 'rows')->assertJsonPath('paginationInfo.totalRowsCount', 11)
            ->assertJsonPath('rows.0.normailPrice', 50)->assertJsonPath('rows.3.normailPrice', 47);
        $this->getJson('/OnlineStore/Catalog?availableOnly=true')->assertOk()->assertJsonPath('paginationInfo.totalRowsCount', 34);
        $this->getJson('/OnlineStore/Catalog?per_page=49')->assertUnprocessable();
        DB::table('online_store_categories')->insert(['id' => 81002, 'name_translations' => json_encode(['en' => 'Hidden category']), 'is_active' => false, 'sort_order' => 0]);
        DB::table('online_store_category_listing')->insert(['online_store_category_id' => 81002, 'online_store_listing_id' => 800000001, 'sort_order' => 0]);
        $this->getJson('/OnlineStore/Catalog?category_id=81002')->assertOk()
            ->assertJsonCount(0, 'rows')->assertJsonPath('paginationInfo.totalRowsCount', 0);
    }

    public function test_promotion_price_filters_match_the_existing_price_authority(): void
    {
        $this->withoutExceptionHandling();
        $this->seedCatalog(5);
        $admin = OnlineStoreFixtureFactory::createAdminActor();
        app(PromotionService::class)->save($admin, ['name' => 'Half price', 'discount_type' => 'percentage',
            'discount_value' => 50, 'scope' => 'global', 'applies_to' => 'retail', 'priority' => 1,
            'is_active' => true, 'targets' => []]);
        $rows = $this->getJson('/OnlineStore/Catalog?onSale=true&minPrice=1&maxPrice=2&sort=price_asc')
            ->assertOk()->assertJsonPath('paginationInfo.totalRowsCount', 3)->json('rows');
        foreach ($rows as $row) {
            $listing = OnlineStoreListing::findOrFail($row['listingId']);
            $this->assertEquals(app(StorePricingService::class)->displayPrices($listing)['retail']['final'], $row['normailPrice']);
        }
    }

    public function test_incomplete_products_do_not_leave_holes_or_inflate_totals(): void
    {
        $this->seedCatalog(30);
        DB::table('products')->where('id', 800000001)->update(['descriptionEng' => '', 'descriptionAr' => '', 'descriptionAbree' => '']);
        DB::table('online_store_media_presentations')->where('online_store_listing_id', 800000002)->delete();
        DB::table('online_store_listings')->where('id', 800000003)->update(['status' => 'hidden']);
        $this->getJson('/OnlineStore/Catalog')->assertOk()->assertJsonCount(24, 'rows')->assertJsonPath('paginationInfo.totalRowsCount', 27);
    }

    #[\PHPUnit\Framework\Attributes\Group('performance')]
    public function test_ten_thousand_products_have_bounded_queries_and_two_second_p95(): void
    {
        $this->seedCatalog(10000);
        $durations = [];
        $queryCounts = [];
        for ($iteration = 0; $iteration < 20; $iteration++) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $start = hrtime(true);
            $this->getJson('/OnlineStore/Catalog?sort=price_asc&availableOnly=true')->assertOk()->assertJsonCount(24, 'rows');
            $durations[] = (hrtime(true) - $start) / 1_000_000_000;
            $queryCount = count(DB::getQueryLog());
            if ($iteration === 19) {
                file_put_contents(storage_path('logs/store-catalog-query-profile.json'), json_encode(DB::getQueryLog(), JSON_PRETTY_PRINT));
            }
            $queryCounts[] = $queryCount;
            DB::disableQueryLog();
            $this->assertLessThanOrEqual(40, $queryCount, 'Catalog query count must not grow with the number of returned products.');
        }
        sort($durations);
        $p95 = $durations[(int) ceil(count($durations) * 0.95) - 1];
        fwrite(STDOUT, sprintf("\nCatalog 10k: p95=%.3fs; queries=%d; samples=%d\n", $p95, max($queryCounts), count($durations)));
        $this->assertLessThanOrEqual(2.0, $p95, 'Catalog p95 exceeded two seconds.');
    }
}
