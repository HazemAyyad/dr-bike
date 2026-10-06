<?php

namespace Tests\Unit\OnlineStore;

use Database\Seeders\OnlineStoreHomeDemoSeeder;
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
}
