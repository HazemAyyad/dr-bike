<?php

namespace Tests\Feature\OnlineStore;

use App\Models\OnlineStore\OnlineStoreListing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\OnlineStoreFixtureFactory;
use Tests\TestCase;

class OnlineStoreCategoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (filter_var(env('ONLINE_STORE_DUMP_DERIVED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Fresh-schema tests cannot alter restored dump-derived state.');
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    public function test_hierarchy_cycle_membership_inactive_consequence_and_complete_sibling_reorder(): void
    {
        $admin = OnlineStoreFixtureFactory::createAuthenticatedAdminActor();
        $product = OnlineStoreFixtureFactory::createProduct(['descriptionEng' => 'Description']);
        $listing = OnlineStoreListing::query()->forceCreate(['product_id' => $product->id, 'status' => 'published', 'readiness_state' => 'complete', 'created_by' => $admin->id]);
        $storeSectionsBefore = DB::table('store_sections')->count();

        $root = $this->postJson('/api/online-store/categories', ['name_translations' => ['en' => 'Root']])->assertCreated()->json('data');
        $sibling = $this->postJson('/api/online-store/categories', ['name_translations' => ['en' => 'Sibling']])->assertCreated()->json('data');
        $child = $this->postJson('/api/online-store/categories', ['parent_id' => $root['id'], 'name_translations' => ['en' => 'Child']])->assertCreated()->json('data');
        $this->patchJson('/api/online-store/categories/'.$root['id'], ['parent_id' => $child['id']])->assertUnprocessable();

        $this->putJson('/api/online-store/categories/'.$root['id'].'/listings', ['items' => [['listing_id' => $listing->id, 'sort_order' => 0]]])->assertOk();
        $this->postJson('/api/online-store/categories/reorder', ['category_ids' => [$sibling['id']]])->assertUnprocessable();
        $this->postJson('/api/online-store/categories/reorder', ['category_ids' => [$sibling['id'], $root['id']]])->assertOk();
        $this->assertSame([$sibling['id'], $root['id']], DB::table('online_store_categories')->whereNull('parent_id')->orderBy('sort_order')->orderBy('id')->pluck('id')->all());
        $this->patchJson('/api/online-store/categories/'.$root['id'], ['is_active' => false])->assertOk();

        $this->assertSame('draft', $listing->fresh()->status);
        $this->assertContains('missing_active_category', $listing->fresh()->readiness_issues);
        $this->assertSame($storeSectionsBefore, DB::table('store_sections')->count());
        $this->assertDatabaseHas('online_store_category_listing', ['online_store_category_id' => $root['id'], 'online_store_listing_id' => $listing->id]);
    }
}
