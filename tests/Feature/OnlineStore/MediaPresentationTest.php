<?php

namespace Tests\Feature\OnlineStore;

use App\Models\Image3dProduct;
use App\Models\NormalImageProduct;
use App\Models\ViewImageProduct;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\OnlineStoreFixtureFactory;
use Tests\TestCase;

class MediaPresentationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (filter_var(env('ONLINE_STORE_DUMP_DERIVED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Fresh-schema tests cannot alter restored dump-derived state.');
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    public function test_first_listing_initializes_valid_media_in_application_priority_once(): void
    {
        $product = OnlineStoreFixtureFactory::createProduct(['descriptionEng' => 'Description']);
        OnlineStoreFixtureFactory::createAuthenticatedAdminActor();
        ViewImageProduct::query()->forceCreate(['id' => 30, 'itemId' => $product->id, 'imageUrl' => 'view-30.jpg']);
        ViewImageProduct::query()->forceCreate(['id' => 10, 'itemId' => $product->id, 'imageUrl' => 'view-10.jpg']);
        ViewImageProduct::query()->forceCreate(['id' => 11, 'itemId' => $product->id, 'imageUrl' => '']);
        NormalImageProduct::query()->forceCreate(['id' => 5, 'itemId' => $product->id, 'imageUrl' => 'normal-5.jpg']);
        Image3dProduct::query()->forceCreate(['id' => 2, 'itemId' => $product->id, 'imageUrl' => '3d-2.jpg']);

        $listing = $this->postJson('/api/online-store/listings', ['product_id' => $product->id])->assertCreated()->json('data');
        $rows = DB::table('online_store_media_presentations')->where('online_store_listing_id', $listing['id'])->orderBy('sort_order')->get();
        $this->assertSame(['view_image:10', 'view_image:30', 'normal_image:5', 'image3d:2'], $rows->map(fn ($row) => $row->source_type.':'.$row->source_id)->all());
        $this->assertTrue((bool) $rows->first()->is_main);
        $this->assertSame(1, $rows->where('is_main', 1)->count());

        $this->postJson('/api/online-store/listings/'.$listing['id'].'/media/initialize')->assertOk();
        $this->assertDatabaseCount('online_store_media_presentations', 4);
    }

    public function test_media_less_draft_cannot_publish_until_a_usable_visible_main_exists(): void
    {
        $product = OnlineStoreFixtureFactory::createProduct(['descriptionEng' => 'Description']);
        OnlineStoreFixtureFactory::createAuthenticatedAdminActor();
        $listing = $this->postJson('/api/online-store/listings', ['product_id' => $product->id])->assertCreated()->json('data');
        $category = $this->postJson('/api/online-store/categories', ['name_translations' => ['en' => 'Category']])->assertCreated()->json('data');
        $this->putJson('/api/online-store/categories/'.$category['id'].'/listings', ['items' => [['listing_id' => $listing['id'], 'sort_order' => 0]]])->assertOk();
        DB::table('online_store_listings')->where('id', $listing['id'])->update(['status' => 'ready']);
        $this->postJson('/api/online-store/listings/'.$listing['id'].'/transition', ['status' => 'published'])->assertUnprocessable();
        $this->assertContains('missing_main_media', DB::table('online_store_listings')->where('id', $listing['id'])->value('readiness_issues') ? json_decode(DB::table('online_store_listings')->where('id', $listing['id'])->value('readiness_issues'), true) : []);
    }
}
