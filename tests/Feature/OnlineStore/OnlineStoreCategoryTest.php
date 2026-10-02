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

    public function test_referenced_category_is_deactivated_without_removing_typed_targets(): void
    {
        OnlineStoreFixtureFactory::createAuthenticatedAdminActor();
        $category = $this->postJson('/api/online-store/categories', ['name_translations' => ['en' => 'Used']])->assertCreated()->json('data');
        $section = $this->postJson('/api/online-store/home-sections', ['key' => 'categories', 'section_type' => 'categories', 'selection_mode' => 'manual'])->assertCreated()->json('data');
        $this->putJson('/api/online-store/home-sections/'.$section['id'].'/items', ['items' => [['target_type' => 'category', 'target_id' => $category['id'], 'sort_order' => 0]]])->assertOk();

        $this->deleteJson('/api/online-store/categories/'.$category['id'])
            ->assertOk()
            ->assertJsonPath('data.disposition', 'deactivated')
            ->assertJsonPath('data.category.is_active', false);

        $this->assertDatabaseHas('online_store_categories', ['id' => $category['id'], 'is_active' => false]);
        $this->assertDatabaseHas('online_store_home_section_items', ['home_section_id' => $section['id'], 'target_type' => 'category', 'target_id' => $category['id']]);

        $unused = $this->postJson('/api/online-store/categories', ['name_translations' => ['en' => 'Unused']])->assertCreated()->json('data');
        $this->deleteJson('/api/online-store/categories/'.$unused['id'])->assertOk()->assertJsonPath('data.disposition', 'deactivated');
        $this->assertDatabaseHas('online_store_categories', ['id' => $unused['id'], 'is_active' => false]);
    }
}
