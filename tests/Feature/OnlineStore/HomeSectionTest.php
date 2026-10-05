<?php

namespace Tests\Feature\OnlineStore;

use App\Models\OnlineStore\OnlineStoreListing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\OnlineStoreFixtureFactory;
use Tests\TestCase;

class HomeSectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (filter_var(env('ONLINE_STORE_DUMP_DERIVED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Fresh-schema tests cannot alter restored dump-derived state.');
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    public function test_typed_target_matrix_and_server_owned_automatic_configuration(): void
    {
        OnlineStoreFixtureFactory::createAuthenticatedAdminActor();
        $category = $this->postJson('/api/online-store/categories', ['name_translations' => ['en' => 'Category']])->assertCreated()->json('data');
        $categories = $this->postJson('/api/online-store/home-sections', ['key' => 'categories', 'section_type' => 'categories', 'selection_mode' => 'manual'])->assertCreated()->json('data');
        $this->putJson('/api/online-store/home-sections/'.$categories['id'].'/items', ['items' => [['target_type' => 'category', 'target_id' => $category['id'], 'sort_order' => 0]]])->assertOk();
        $this->putJson('/api/online-store/home-sections/'.$categories['id'].'/items', ['items' => [['target_type' => 'App\\Models\\Product', 'target_id' => 1, 'sort_order' => 0]]])->assertUnprocessable();

        $product = OnlineStoreFixtureFactory::createProduct();
        $listing = OnlineStoreListing::query()->forceCreate(['product_id' => $product->id, 'status' => 'published', 'readiness_state' => 'complete']);
        DB::table('online_store_category_listing')->insert(['online_store_category_id' => $category['id'], 'online_store_listing_id' => $listing->id, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $offers = $this->postJson('/api/online-store/home-sections', ['key' => 'offers', 'section_type' => 'offers', 'selection_mode' => 'manual'])->assertCreated()->json('data');
        $this->putJson('/api/online-store/home-sections/'.$offers['id'].'/items', ['items' => [['target_type' => 'listing', 'target_id' => $listing->id, 'sort_order' => 0]]])->assertOk();
        $this->putJson('/api/online-store/home-sections/'.$offers['id'].'/items', ['items' => [['target_type' => 'category', 'target_id' => $category['id'], 'sort_order' => 0]]])->assertUnprocessable();

        $this->postJson('/api/online-store/home-sections', ['key' => 'bad-hero', 'section_type' => 'hero', 'selection_mode' => 'manual'])->assertUnprocessable();
        $this->postJson('/api/online-store/home-sections', ['key' => 'hero', 'section_type' => 'hero', 'selection_mode' => 'dedicated_banners'])->assertCreated();
        $this->postJson('/api/online-store/home-sections', ['key' => 'maintenance', 'section_type' => 'maintenance', 'selection_mode' => 'automatic', 'selection_config' => ['destination' => 'maintenance.request']])->assertCreated();
        $this->postJson('/api/online-store/home-sections', ['key' => 'unsafe', 'section_type' => 'recent', 'selection_mode' => 'automatic', 'selection_config' => ['selector' => 'recent', 'sql' => 'drop table products']])->assertUnprocessable();
        $this->postJson('/api/online-store/home-sections', ['key' => 'recent', 'section_type' => 'recent', 'selection_mode' => 'automatic', 'selection_config' => ['selector' => 'recent', 'limit' => 10]])->assertCreated();

        $response = $this->getJson('/OnlineStore/Home')->assertOk();
        $this->assertSame(['categories', 'offers', 'hero', 'maintenance', 'recent'], collect($response->json('data.sections'))->pluck('key')->all());
        $this->assertSame('category', $response->json('data.sections.0.items.0.target_type'));
        $this->assertSame('listing', $response->json('data.sections.1.items.0.target_type'));
    }
}
