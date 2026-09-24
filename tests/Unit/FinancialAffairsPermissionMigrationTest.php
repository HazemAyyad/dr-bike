<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FinancialAffairsPermissionMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_en')->unique();
            $table->string('grant_policy')->nullable();
            $table->timestamps();
        });
        Schema::create('employee_permissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('permission_id');
            $table->timestamps();
        });
    }

    public function test_it_seeds_detailed_permissions_and_backfills_legacy_holders_without_duplicates(): void
    {
        $legacyId = DB::table('permissions')->insertGetId([
            'name' => 'المصاريف والأمور المالية',
            'name_en' => 'Expenses and Financial Affairs',
            'grant_policy' => 'permissions_manage',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employee_permissions')->insert([
            'employee_id' => 7,
            'permission_id' => $legacyId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_09_24_210000_seed_financial_affairs_detailed_permissions.php');
        $migration->up();
        $migration->up();

        $detailedIds = DB::table('permissions')
            ->where('name_en', 'like', 'Financial %')
            ->pluck('id');

        $this->assertCount(14, $detailedIds);
        $this->assertSame(
            14,
            DB::table('employee_permissions')
                ->where('employee_id', 7)
                ->whereIn('permission_id', $detailedIds)
                ->count()
        );
        $this->assertDatabaseHas('employee_permissions', [
            'employee_id' => 7,
            'permission_id' => $legacyId,
        ]);
    }
}
