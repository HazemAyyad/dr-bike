<?php

namespace Tests\Unit\OnlineStore;

use App\Http\Resources\OnlineStore\OnlineStoreListingResource;
use App\Models\OnlineStore\OnlineStoreListing;
use App\Services\OnlineStore\ListingReadinessService;
use App\Support\OnlineStore\OnlineStoreValues;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class OnlineStoreListingTest extends TestCase
{
    public function test_legacy_store_product_maps_listing_by_product_foreign_key(): void
    {
        $source = file_get_contents(__DIR__.'/../../../app/Models/Store/StoreProduct.php');

        $this->assertStringContainsString('function onlineStoreListing(): HasOne', $source);
        $this->assertStringContainsString("hasOne(OnlineStoreListing::class, 'product_id')", $source);
    }

    public function test_model_exposes_merchandising_fields_but_no_authoritative_price_or_stock_fields(): void
    {
        $listing = new OnlineStoreListing;
        $fillable = $listing->getFillable();

        $this->assertContains('product_id', $fillable);
        $this->assertContains('name_translations', $fillable);
        $this->assertContains('is_featured', $fillable);
        $this->assertContains('online_stock_limit', $fillable);
        $this->assertNotContains('status', $fillable);
        $this->assertNotContains('readiness_state', $fillable);
        foreach (['price', 'normailPrice', 'wholesalePrice', 'stock'] as $field) {
            $this->assertNotContains($field, $fillable);
        }
    }

    public function test_online_stock_limit_uses_a_separate_nullable_listing_column(): void
    {
        $migration = file_get_contents(__DIR__.'/../../../database/migrations/2026_10_06_000001_add_online_stock_limit_to_online_store_listings.php');

        $this->assertStringContainsString("unsignedInteger('online_stock_limit')->nullable()", $migration);
        $this->assertSame('integer', (new OnlineStoreListing)->getCasts()['online_stock_limit']);
    }

    public function test_model_casts_translation_flags_readiness_and_lifecycle_timestamps(): void
    {
        $casts = (new OnlineStoreListing)->getCasts();
        foreach (['name_translations', 'description_translations', 'badge_translations', 'readiness_issues'] as $field) {
            $this->assertSame('array', $casts[$field]);
        }
        foreach (['is_featured', 'is_new', 'show_on_home', 'show_as_offer'] as $field) {
            $this->assertSame('boolean', $casts[$field]);
        }
        $this->assertSame('datetime', $casts['published_at']);
        $this->assertSame('datetime', $casts['hidden_at']);
    }

    public function test_foundation_migration_enforces_one_listing_per_product_and_safe_defaults(): void
    {
        $migration = file_get_contents(__DIR__.'/../../../database/migrations/2026_10_01_000002_create_online_store_catalog_tables.php');
        $this->assertStringContainsString("foreignId('product_id')->unique()", $migration);
        $this->assertStringContainsString("string('status', 20)->default('draft')", $migration);
        $this->assertStringContainsString("string('readiness_state', 20)->default('incomplete')", $migration);
        $this->assertStringContainsString("json('badge_translations')->nullable()", $migration);
        $this->assertStringContainsString("json('name_translations')->nullable()", $migration);
        $this->assertStringContainsString("json('description_translations')->nullable()", $migration);
        foreach (['is_featured', 'is_new', 'show_on_home', 'show_as_offer'] as $field) {
            $this->assertStringContainsString("boolean('{$field}')->default(false)", $migration);
        }
        $this->assertSame(['draft', 'ready', 'published', 'hidden'], OnlineStoreValues::LISTING_STATUSES);
        $this->assertStringNotContainsString("->float('price'", $migration);
        $this->assertStringNotContainsString("->integer('stock'", $migration);
    }

    public function test_missing_product_is_invalid_for_readiness_and_safe_serialization(): void
    {
        $listing = new OnlineStoreListing([
            'name_translations' => ['en' => 'Store name'],
            'description_translations' => ['en' => 'Store description'],
        ]);
        $listing->forceFill([
            'id' => 10,
            'product_id' => 99,
            'status' => 'published',
            'readiness_state' => 'complete',
            'readiness_issues' => [],
        ]);
        $listing->exists = true;
        $listing->setRelation('product', null);

        $readiness = (new ListingReadinessService)->evaluate($listing);
        $serialized = (new OnlineStoreListingResource($listing))->toArray(Request::create('/'));

        $this->assertSame(['state' => 'incomplete', 'issues' => ['missing_product']], $readiness);
        $this->assertSame('incomplete', $serialized['readiness_state']);
        $this->assertSame(['missing_product'], $serialized['readiness_issues']);
        $this->assertFalse($serialized['product_archived']);
        $this->assertSame(['retail' => null, 'wholesale' => null, 'variants' => []], $serialized['base_prices']);
        $this->assertSame([
            'visible' => false, 'purchasable' => false, 'available_qty' => 0,
            'physical_stock' => 0, 'reserved_qty' => 0, 'variants' => [],
        ], $serialized['availability']);
        $this->assertSame('Store name', $serialized['display']['name']);
    }
}
