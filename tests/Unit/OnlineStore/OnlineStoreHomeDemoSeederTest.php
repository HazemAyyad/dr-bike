<?php

namespace Tests\Unit\OnlineStore;

use Database\Seeders\OnlineStoreHomeDemoSeeder;
use Database\Seeders\OnlineStoreShowcaseSeeder;
use PHPUnit\Framework\TestCase;

class OnlineStoreHomeDemoSeederTest extends TestCase
{
    public function test_demo_seeder_defines_electric_store_assets_and_idempotent_keys(): void
    {
        $this->assertSame(
            ['hero', 'categories', 'featured', 'recent', 'best_sellers', 'offers', 'maintenance'],
            OnlineStoreHomeDemoSeeder::SECTION_KEYS,
        );
        $this->assertCount(3, OnlineStoreHomeDemoSeeder::BANNER_PATHS);
        foreach (OnlineStoreHomeDemoSeeder::BANNER_PATHS as $path) {
            $this->assertFileExists(__DIR__.'/../../../public/'.$path);
        }

        $source = file_get_contents(__DIR__.'/../../../database/seeders/OnlineStoreHomeDemoSeeder.php');
        $this->assertStringContainsString('updateOrCreate', $source);
        $this->assertStringNotContainsString('motorcycle oil', strtolower($source));
        $this->assertStringContainsString('الدراجات الكهربائية', $source);
    }

    public function test_public_deploy_runs_the_demo_seed_only_when_missing(): void
    {
        $source = file_get_contents(__DIR__.'/../../../public/deploy_once.php');

        $this->assertStringContainsString("'--class' => 'OnlineStoreHomeDemoSeeder'", $source);
        $this->assertStringContainsString("'guard' => 'online_store_home_demo'", $source);
        $this->assertStringContainsString('OnlineStoreHomeDemoSeeder::exists()', $source);
    }

    public function test_showcase_seeder_is_deployed_and_preserves_existing_catalog_content(): void
    {
        $deploy = file_get_contents(__DIR__.'/../../../public/deploy_once.php');
        $source = file_get_contents(__DIR__.'/../../../database/seeders/OnlineStoreShowcaseSeeder.php');

        $this->assertStringContainsString("'--class' => 'OnlineStoreShowcaseSeeder'", $deploy);
        $this->assertStringContainsString("whereNull('parent_id')", $source);
        $this->assertStringContainsString('normalizedName', $source);
        $this->assertStringContainsString('if (! $category)', $source);
        $this->assertStringNotContainsString('if ($categories->isEmpty())', $source);
        $this->assertStringContainsString("where('status', 'published')", $source);
        $this->assertStringContainsString("where('readiness_state', 'complete')", $source);
        $this->assertStringContainsString('OnlineStoreHomeDemoSeeder::class', $source);
        $this->assertStringNotContainsString('truncate', strtolower($source));
    }

    public function test_showcase_categories_use_dedicated_svg_icons(): void
    {
        $this->assertCount(7, OnlineStoreShowcaseSeeder::CATEGORY_ICON_PATHS);

        foreach (OnlineStoreShowcaseSeeder::CATEGORY_ICON_PATHS as $path) {
            $this->assertStringEndsWith('.svg', $path);
            $this->assertFileExists(__DIR__.'/../../../public/'.$path);
        }

        $source = file_get_contents(__DIR__.'/../../../database/seeders/OnlineStoreShowcaseSeeder.php');
        $this->assertStringContainsString('usesLegacyShowcaseImage', $source);
        $this->assertStringContainsString("update(['image_path' => \$data['image_path']])", $source);
    }
}
