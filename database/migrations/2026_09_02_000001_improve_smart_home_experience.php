<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('smart_devices') && ! Schema::hasColumn('smart_devices', 'display_order')) {
            Schema::table('smart_devices', function (Blueprint $table) {
                $table->unsignedInteger('display_order')->default(0)->after('smart_room_id');
                $table->index(['smart_home_id', 'smart_room_id', 'display_order'], 'smart_devices_display_order_index');
            });
        }

        if (Schema::hasTable('smart_device_schedules')) {
            Schema::table('smart_device_schedules', function (Blueprint $table) {
                if (! Schema::hasColumn('smart_device_schedules', 'commands')) {
                    $table->json('commands')->nullable()->after('command_value');
                }
                if (! Schema::hasColumn('smart_device_schedules', 'recurrence_config')) {
                    $table->json('recurrence_config')->nullable()->after('repeat_days');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('smart_device_schedules')) {
            Schema::table('smart_device_schedules', function (Blueprint $table) {
                if (Schema::hasColumn('smart_device_schedules', 'recurrence_config')) {
                    $table->dropColumn('recurrence_config');
                }
                if (Schema::hasColumn('smart_device_schedules', 'commands')) {
                    $table->dropColumn('commands');
                }
            });
        }

        if (Schema::hasTable('smart_devices') && Schema::hasColumn('smart_devices', 'display_order')) {
            Schema::table('smart_devices', function (Blueprint $table) {
                $table->dropIndex('smart_devices_display_order_index');
                $table->dropColumn('display_order');
            });
        }
    }
};
