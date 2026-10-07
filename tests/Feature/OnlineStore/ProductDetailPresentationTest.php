<?php

namespace Tests\Feature\OnlineStore;

use App\Models\NormalImageProduct;
use App\Models\OnlineStore\OnlineStoreListing;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\OnlineStoreFixtureFactory;
use Tests\TestCase;

class ProductDetailPresentationTest extends TestCase
{
    private string $originalPublicPath;

    private string $testPublicPath;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->originalPublicPath = public_path();
        $this->testPublicPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'doctor-bike-product-detail-'.Str::uuid();
        File::ensureDirectoryExists($this->testPublicPath);
        app()->usePublicPath($this->testPublicPath);
    }

    protected function tearDown(): void
    {
        app()->usePublicPath($this->originalPublicPath);
        File::deleteDirectory($this->testPublicPath);
        parent::tearDown();
    }

    public function test_listing_detail_presentation_is_store_only_and_reaches_the_customer_contract(): void
    {
        $product = OnlineStoreFixtureFactory::createProduct([
            'nameAr' => 'الاسم الأصلي',
            'descriptionEng' => 'Original description',
            'normailPrice' => 120,
            'stock' => 7,
        ]);
        OnlineStoreFixtureFactory::createAuthenticatedAdminActor();
        $listing = $this->postJson('/api/online-store/listings', ['product_id' => $product->id])
            ->assertCreated()->json('data');

        $presentation = [
            'brand_translations' => ['ar' => 'Doctor Bike'],
            'quick_specs' => [[
                'icon' => 'speed',
                'label_translations' => ['ar' => 'السرعة'],
                'value_translations' => ['ar' => '25 كم/ساعة'],
            ]],
            'shipping_warranty_translations' => ['ar' => 'ضمان سنة'],
            'return_policy_translations' => ['ar' => 'إرجاع خلال 14 يوماً'],
        ];
        $this->patchJson('/api/online-store/listings/'.$listing['id'], [
            'detail_presentation' => $presentation,
        ])->assertOk()->assertJsonPath('data.detail_presentation.quick_specs.0.icon', 'speed');

        $listingModel = OnlineStoreListing::query()->findOrFail($listing['id']);
        $categoryId = DB::table('online_store_categories')->insertGetId([
            'name_translations' => json_encode(['ar' => 'متجر']),
            'is_active' => true, 'show_on_home' => false, 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('online_store_category_listing')->insert([
            'online_store_category_id' => $categoryId,
            'online_store_listing_id' => $listingModel->id,
            'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $image = NormalImageProduct::query()->forceCreate([
            'id' => $listingModel->id + 9000,
            'itemId' => $product->id,
            'imageUrl' => 'products/main.jpg',
        ]);
        DB::table('online_store_media_presentations')->insert([
            'online_store_listing_id' => $listingModel->id,
            'source_type' => 'normal_image', 'source_id' => $image->id,
            'is_main' => true, 'is_visible' => true, 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $listingModel->forceFill(['status' => 'published', 'readiness_state' => 'complete'])->save();

        $this->postJson('/Items/GetItemById', ['itemId' => $product->id])
            ->assertOk()
            ->assertJsonPath('normailPrice', 120)
            ->assertJsonPath('oldPrice', null)
            ->assertJsonPath('discount', 0)
            ->assertJsonPath('storePresentation.quick_specs.0.value_translations.ar', '25 كم/ساعة');

        $this->assertSame('الاسم الأصلي', $product->fresh()->nameAr);
        $this->assertSame(120.0, (float) $product->fresh()->normailPrice);
    }

    public function test_product_media_endpoint_accepts_real_video_and_returns_typed_metadata(): void
    {
        OnlineStoreFixtureFactory::createAuthenticatedAdminActor();

        $response = $this->post('/api/online-store/product-media', [
            'file' => UploadedFile::fake()->create('product.mp4', 500, 'video/mp4'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $response->assertJsonPath('data.media_type', 'video')
            ->assertJsonPath('data.mime_type', 'video/mp4');
        $this->assertMatchesRegularExpression(
            '#^public/OnlineStore/Products/[0-9a-f-]+\.mp4$#',
            $response->json('data.path'),
        );
    }
}
