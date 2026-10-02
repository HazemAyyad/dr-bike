<?php

namespace Tests\Unit\OnlineStore;

use App\Models\OnlineStore\OnlineStoreListing;
use App\Support\OnlineStore\OnlineStoreValues;
use PHPUnit\Framework\TestCase;

class OnlineStoreListingTest extends TestCase
{
    public function test_model_exposes_merchandising_fields_but_no_authoritative_price_or_stock_fields(): void
    {
        $listing = new OnlineStoreListing;
        $fillable = $listing->getFillable();

        $this->assertContains('product_id', $fillable);
        $this->assertContains('name_translations', $fillable);
        $this->assertContains('is_featured', $fillable);
        $this->assertNotContains('status', $fillable);
        $this->assertNotContains('readiness_state', $fillable);
        foreach (['price', 'normailPrice', 'wholesalePrice', 'stock'] as $field) {
            $this->assertNotContains($field, $fillable);
        }
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
}
