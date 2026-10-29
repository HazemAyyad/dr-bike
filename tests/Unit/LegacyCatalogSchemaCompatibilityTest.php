<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LegacyCatalogSchemaCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
    }

    public function test_fresh_schema_keeps_category_data_and_adds_only_missing_product_fields(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar');
            $table->boolean('is_show')->default(true);
        });
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->decimal('min_sale_price', 14, 4)->nullable();
        });
        DB::table('categories')->insert(['id' => 1, 'name_ar' => 'دراجات', 'is_show' => false]);
        DB::table('products')->insert(['id' => 1, 'min_sale_price' => 25]);
        $migration = require database_path('migrations/2026_10_29_000001_complete_legacy_catalog_schema_for_fresh_installs.php');
        $migration->up();
        $migration->up();
        $migration->down();

        $this->assertSame('دراجات', DB::table('categories')->value('nameAr'));
        $this->assertSame(0, DB::table('categories')->value('isShow'));
        $this->assertEquals(25, DB::table('products')->value('min_sale_price'));
        $this->assertTrue(Schema::hasColumns('products', ['project_id', 'min_stock', 'is_sold_with_paper', 'rotation_date']));
        $this->assertSame(0, DB::table('products')->value('min_stock'));
        $this->assertNull(DB::table('products')->value('project_id'));
    }

    public function test_existing_legacy_names_and_values_are_preserved(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->string('nameAr');
            $table->string('name_ar')->nullable();
        });
        DB::table('categories')->insert(['id' => 1, 'nameAr' => 'القيمة الحالية', 'name_ar' => 'قيمة أخرى']);
        (require database_path('migrations/2026_10_29_000001_complete_legacy_catalog_schema_for_fresh_installs.php'))->up();

        $this->assertSame('القيمة الحالية', DB::table('categories')->value('nameAr'));
        $this->assertSame('قيمة أخرى', DB::table('categories')->value('name_ar'));
    }
}
