<?php

namespace Tests\Unit\OnlineStore;

use PHPUnit\Framework\TestCase;

class OnlineStoreMigrationIsolationTest extends TestCase
{
    public function test_fresh_and_dump_derived_modes_are_mutually_exclusive(): void
    {
        $root = dirname(__DIR__, 3);
        $fresh = file_get_contents($root.'/tests/Feature/OnlineStore/OnlineStoreSchemaMigrationTest.php');
        $dumpDerived = file_get_contents($root.'/tests/Feature/OnlineStore/OnlineStoreDumpDerivedSchemaMigrationTest.php');

        $this->assertStringContainsString('ONLINE_STORE_DUMP_DERIVED_SCHEMA_READY', $fresh);
        $this->assertStringContainsString("Artisan::call('migrate:fresh'", $fresh);
        $this->assertStringContainsString('ONLINE_STORE_DUMP_DERIVED_SCHEMA_READY', $dumpDerived);
        $this->assertStringNotContainsString('migrate:fresh', $dumpDerived);
        $this->assertStringNotContainsString('Artisan', $dumpDerived);
    }
}
