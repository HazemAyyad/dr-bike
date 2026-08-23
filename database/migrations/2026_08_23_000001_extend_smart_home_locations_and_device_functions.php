<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('smart_homes') && ! Schema::hasColumn('smart_homes', 'type')) {
            Schema::table('smart_homes', function (Blueprint $table) {
                $table->string('type', 40)->default('home')->after('name')->index();
            });
        }

        if (Schema::hasTable('smart_devices') && ! Schema::hasColumn('smart_devices', 'user_id')) {
            Schema::table('smart_devices', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->after('id')->index();
            });

            DB::statement('UPDATE smart_devices d INNER JOIN smart_homes h ON h.id = d.smart_home_id SET d.user_id = h.user_id');

            Schema::table('smart_devices', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('smart_devices') && Schema::hasColumn('smart_devices', 'smart_home_id')) {
            $this->dropForeignIfExists('smart_devices', 'smart_devices_smart_home_id_foreign');
            DB::statement('ALTER TABLE smart_devices MODIFY smart_home_id BIGINT UNSIGNED NULL');
            Schema::table('smart_devices', function (Blueprint $table) {
                $table->foreign('smart_home_id')->references('id')->on('smart_homes')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('smart_device_functions')) {
            Schema::create('smart_device_functions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('smart_device_id');
                $table->string('dp_id', 64)->nullable();
                $table->string('code', 120);
                $table->string('display_name')->nullable();
                $table->string('function_type', 80)->nullable();
                $table->string('icon', 80)->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_visible')->default(true);
                $table->timestamps();

                $table->unique(['smart_device_id', 'code'], 'smart_device_functions_device_code_unique');
                $table->index(['smart_device_id', 'sort_order']);
                $table->index(['smart_device_id', 'dp_id']);
                $table->foreign('smart_device_id')->references('id')->on('smart_devices')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_device_functions');

        if (Schema::hasTable('smart_devices') && Schema::hasColumn('smart_devices', 'smart_home_id')) {
            $this->dropForeignIfExists('smart_devices', 'smart_devices_smart_home_id_foreign');
            DB::statement('ALTER TABLE smart_devices MODIFY smart_home_id BIGINT UNSIGNED NOT NULL');
            Schema::table('smart_devices', function (Blueprint $table) {
                $table->foreign('smart_home_id')->references('id')->on('smart_homes')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('smart_devices') && Schema::hasColumn('smart_devices', 'user_id')) {
            $this->dropForeignIfExists('smart_devices', 'smart_devices_user_id_foreign');
            Schema::table('smart_devices', function (Blueprint $table) {
                $table->dropColumn('user_id');
            });
        }

        if (Schema::hasTable('smart_homes') && Schema::hasColumn('smart_homes', 'type')) {
            Schema::table('smart_homes', function (Blueprint $table) {
                $table->dropColumn('type');
            });
        }
    }

    private function dropForeignIfExists(string $table, string $foreign): void
    {
        $database = DB::getDatabaseName();
        $exists = DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $database)
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $foreign)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();

        if ($exists) {
            DB::statement("ALTER TABLE {$table} DROP FOREIGN KEY {$foreign}");
        }
    }
};
