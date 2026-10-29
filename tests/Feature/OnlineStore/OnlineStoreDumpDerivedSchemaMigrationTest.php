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
        $alreadyHasOrigin = Schema::hasColumn('sales_orders', 'origin');
        $historicalCount = DB::table('sales_orders')->count();
        $originsBefore = $alreadyHasOrigin
            ? DB::table('sales_orders')->orderBy('id')->pluck('origin', 'id')->all()
            : null;

        foreach ($this->phaseTwoMigrationFiles() as $file) {
            $alreadyApplied = Schema::hasTable('migrations')
                && DB::table('migrations')->where('migration', basename($file, '.php'))->exists();
            if (! $alreadyApplied) {
                (require $file)->up();
            }
        }

        $this->assertSame($historicalCount, DB::table('sales_orders')->count());
        if ($originsBefore !== null) {
            $this->assertSame($originsBefore, DB::table('sales_orders')->orderBy('id')->pluck('origin', 'id')->all());
        } else {
            $this->assertSame($historicalCount, DB::table('sales_orders')->where('origin', 'admin')->count());
        }
        $this->assertTrue(Schema::hasTable('online_store_listings'));
        $this->assertTrue(Schema::hasTable('online_store_legacy_checkout_attempts'));
    }

    public function test_catalog_and_administration_reads_work_against_the_restored_schema(): void
    {
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $request = \Illuminate\Http\Request::create('/OnlineStore/Catalog', 'GET', ['per_page' => 2]);
        $response = app(\App\Http\Controllers\API\Store\StoreItemsController::class)
            ->catalog($request, app(\App\Services\OnlineStore\StoreCatalogQuery::class));
        $this->assertSame(200, $response->getStatusCode());
        $payload = $response->getData(true);
        $this->assertIsArray($payload['rows']);
        $this->assertLessThanOrEqual(2, count($payload['rows']));
        $this->assertGreaterThanOrEqual(0, $payload['paginationInfo']['totalRowsCount']);

        $dashboard = app(\App\Services\OnlineStore\OnlineStoreDashboardService::class)->summary();
        $this->assertTrue($dashboard['pending_reviews']['available']);
        $this->assertTrue($dashboard['debt_activity']['available']);
        $this->assertSame(DB::table('sales_orders')->where('origin', 'store')->count(), $dashboard['orders']['store_count']);
        $report = app(\App\Services\OnlineStore\OnlineStoreReportService::class)->report(['origin' => 'store']);
        $this->assertSame($dashboard['orders']['store_count'], $report['orders']['count']);
        $this->assertSame($dashboard['orders']['store_total'], $report['orders']['total']);
        $this->assertIsArray(app(\App\Services\CashboxReportService::class)->permanentBoxes()->all());
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
