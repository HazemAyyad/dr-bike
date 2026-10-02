<?php

namespace Tests\Feature\OnlineStore;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OnlineStoreDumpDerivedSchemaMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! filter_var(env('ONLINE_STORE_DUMP_DERIVED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Dump-derived verification runs only after a dump is restored into the guarded disposable schema.');
        }
    }

    public function test_phase_two_migrations_apply_to_the_restored_dump_derived_schema(): void
    {
        $this->assertTrue(Schema::hasTable('sales_orders'));
        $this->assertTrue(Schema::hasTable('products'));
        $this->assertFalse(Schema::hasColumn('sales_orders', 'origin'));

        $historicalCount = DB::table('sales_orders')->count();

        foreach ($this->phaseTwoMigrationFiles() as $file) {
            (require $file)->up();
        }

        $this->assertSame($historicalCount, DB::table('sales_orders')->where('origin', 'admin')->count());
        $this->assertTrue(Schema::hasTable('online_store_listings'));
        $this->assertTrue(Schema::hasTable('online_store_legacy_checkout_attempts'));
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
}
