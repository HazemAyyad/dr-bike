<?php

namespace Tests\Feature\OnlineStore;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OnlineStoreSchemaMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (filter_var(env('ONLINE_STORE_DUMP_DERIVED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Fresh-schema migration tests are disabled while dump-derived verification is active.');
        }
    }

    public function test_fresh_schema_contains_phase_two_tables_columns_and_named_constraints(): void
    {
        $this->freshSchema();

        foreach ($this->onlineStoreTables() as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }

        $this->assertTrue(Schema::hasColumns('sales_orders', [
            'origin', 'origin_user_id', 'client_request_id',
        ]));

        $salesOrderIndexes = collect(Schema::getIndexes('sales_orders'))->pluck('name');
        $this->assertContains('sales_orders_origin_created_idx', $salesOrderIndexes);
        $this->assertContains('sales_orders_origin_actor_request_unique', $salesOrderIndexes);

        $listingIndexes = collect(Schema::getIndexes('online_store_listings'))->pluck('name');
        $this->assertTrue($listingIndexes->contains(
            fn (string $name) => str_contains($name, 'product_id') || $name === 'online_store_listings_product_id_unique'
        ));

        foreach ($this->expectedForeignColumns() as $table => $columns) {
            $actualColumns = collect(Schema::getForeignKeys($table))
                ->flatMap(fn (array $foreign) => $foreign['columns'])
                ->all();
            foreach ($columns as $column) {
                $this->assertContains($column, $actualColumns, "Missing foreign key {$table}.{$column}");
            }
        }

        foreach ($this->expectedUniqueIndexes() as $table => $indexes) {
            $actual = collect(Schema::getIndexes($table))->keyBy('name');
            foreach ($indexes as $index) {
                $this->assertTrue((bool) ($actual->get($index)['unique'] ?? false), "Missing unique index: {$index}");
            }
        }
    }

    public function test_preexisting_core_schema_backfills_every_historical_order_as_admin(): void
    {
        $this->freshSchema();
        $this->rollBackPhaseTwo();

        DB::table('sales_orders')->insert([
            ['serial_number' => 'PHASE2-HISTORICAL-1', 'created_at' => now(), 'updated_at' => now()],
            ['serial_number' => 'PHASE2-HISTORICAL-2', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->applyPhaseTwo();

        $this->assertSame(
            ['admin', 'admin'],
            DB::table('sales_orders')
                ->whereIn('serial_number', ['PHASE2-HISTORICAL-1', 'PHASE2-HISTORICAL-2'])
                ->orderBy('serial_number')
                ->pluck('origin')
                ->all()
        );
    }

    public function test_admin_request_ids_are_nullable_and_request_ids_are_scoped_to_origin_actor(): void
    {
        $this->freshSchema();
        $userOne = $this->createUser('phase2-user-one@example.invalid');
        $userTwo = $this->createUser('phase2-user-two@example.invalid');

        DB::table('sales_orders')->insert([
            'serial_number' => 'PHASE2-ADMIN-NULL',
            'origin' => 'admin',
            'origin_user_id' => null,
            'client_request_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([$userOne, $userTwo] as $index => $userId) {
            DB::table('sales_orders')->insert([
                'serial_number' => 'PHASE2-STORE-'.($index + 1),
                'origin' => 'store',
                'origin_user_id' => $userId,
                'client_request_id' => 'shared-request-id',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertSame(2, DB::table('sales_orders')->where('client_request_id', 'shared-request-id')->count());

        $this->expectException(QueryException::class);
        DB::table('sales_orders')->insert([
            'serial_number' => 'PHASE2-STORE-DUPLICATE',
            'origin' => 'store',
            'origin_user_id' => $userOne,
            'client_request_id' => 'shared-request-id',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_phase_two_rollback_preserves_authoritative_core_rows(): void
    {
        $this->freshSchema();
        $productId = 1900000001;
        DB::table('products')->insert([
            'id' => $productId,
            'product_code' => 'PHASE2-ROLLBACK-PRODUCT',
            'nameAr' => 'Phase 2 rollback fixture',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderId = DB::table('sales_orders')->insertGetId([
            'serial_number' => 'PHASE2-ROLLBACK-ORDER',
            'origin' => 'admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->rollBackPhaseTwo();

        $this->assertTrue(DB::table('products')->where('id', $productId)->exists());
        $this->assertTrue(DB::table('sales_orders')->where('id', $orderId)->exists());
        $this->assertFalse(Schema::hasTable('online_store_listings'));
    }

    private function createUser(string $email): int
    {
        return (int) DB::table('users')->insertGetId([
            'name' => 'Phase 2 Fixture',
            'email' => $email,
            'password' => 'not-a-real-credential',
            'type' => 'User',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function freshSchema(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    private function applyPhaseTwo(): void
    {
        foreach ($this->phaseTwoMigrationFiles() as $file) {
            (require $file)->up();
        }
    }

    private function rollBackPhaseTwo(): void
    {
        foreach (array_reverse($this->phaseTwoMigrationFiles()) as $file) {
            (require $file)->down();
        }
    }

    private function phaseTwoMigrationFiles(): array
    {
        return [
            database_path('migrations/2026_10_01_000001_add_online_store_origin_to_sales_orders.php'),
            database_path('migrations/2026_10_01_000002_create_online_store_catalog_tables.php'),
            database_path('migrations/2026_10_01_000003_create_online_store_discount_tables.php'),
            database_path('migrations/2026_10_01_000004_create_online_store_content_tables.php'),
            database_path('migrations/2026_10_01_000005_create_online_store_account_governance_tables.php'),
        ];
    }

    private function onlineStoreTables(): array
    {
        return [
            'online_store_listings', 'online_store_categories', 'online_store_category_listing',
            'online_store_media_presentations', 'online_store_promotions', 'online_store_promotion_targets',
            'online_store_coupons', 'online_store_coupon_targets', 'online_store_coupon_redemptions',
            'online_store_home_sections', 'online_store_home_section_items', 'online_store_banners',
            'online_store_account_links', 'online_store_credit_policies', 'online_store_reviews',
            'online_store_settings', 'online_store_audit_events', 'online_store_legacy_checkout_attempts',
        ];
    }

    private function expectedForeignColumns(): array
    {
        return [
            'sales_orders' => ['origin_user_id'],
            'online_store_listings' => ['product_id', 'created_by', 'updated_by'],
            'online_store_categories' => ['parent_id', 'created_by', 'updated_by'],
            'online_store_category_listing' => ['online_store_category_id', 'online_store_listing_id'],
            'online_store_media_presentations' => ['online_store_listing_id', 'created_by', 'updated_by'],
            'online_store_promotions' => ['created_by', 'updated_by'],
            'online_store_promotion_targets' => ['promotion_id'],
            'online_store_coupons' => ['created_by', 'updated_by'],
            'online_store_coupon_targets' => ['coupon_id'],
            'online_store_coupon_redemptions' => ['coupon_id', 'sales_order_id', 'user_id', 'customer_id', 'seller_id'],
            'online_store_home_sections' => ['created_by', 'updated_by'],
            'online_store_home_section_items' => ['home_section_id'],
            'online_store_banners' => ['created_by', 'updated_by'],
            'online_store_account_links' => ['user_id', 'customer_id', 'seller_id', 'linked_by', 'verified_by'],
            'online_store_credit_policies' => ['account_link_id', 'approved_by'],
            'online_store_reviews' => ['product_id', 'customer_id', 'user_id', 'sales_order_id', 'moderated_by'],
            'online_store_settings' => ['updated_by'],
            'online_store_audit_events' => ['actor_user_id'],
            'online_store_legacy_checkout_attempts' => ['origin_user_id', 'sales_order_id'],
        ];
    }

    private function expectedUniqueIndexes(): array
    {
        return [
            'sales_orders' => ['sales_orders_origin_actor_request_unique'],
            'online_store_listings' => ['online_store_listings_product_id_unique'],
            'online_store_category_listing' => ['oscl_category_listing_unique'],
            'online_store_media_presentations' => ['osmp_listing_source_unique'],
            'online_store_promotion_targets' => ['ospt_promotion_target_unique'],
            'online_store_coupons' => ['online_store_coupons_code_unique'],
            'online_store_coupon_targets' => ['osct_coupon_target_unique'],
            'online_store_coupon_redemptions' => ['online_store_coupon_redemptions_sales_order_id_unique'],
            'online_store_home_sections' => ['online_store_home_sections_key_unique'],
            'online_store_home_section_items' => ['oshsi_section_target_unique'],
            'online_store_account_links' => ['osal_user_role_unique', 'osal_customer_unique', 'osal_seller_unique'],
            'online_store_credit_policies' => ['online_store_credit_policies_account_link_id_unique'],
            'online_store_legacy_checkout_attempts' => ['oslca_actor_fingerprint_unique'],
        ];
    }
}
