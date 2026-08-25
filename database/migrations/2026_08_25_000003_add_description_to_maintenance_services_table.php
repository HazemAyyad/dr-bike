<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('maintenance_services') && ! Schema::hasColumn('maintenance_services', 'description')) {
            Schema::table('maintenance_services', function (Blueprint $table) {
                $table->text('description')->nullable()->after('name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('maintenance_services') && Schema::hasColumn('maintenance_services', 'description')) {
            Schema::table('maintenance_services', function (Blueprint $table) {
                $table->dropColumn('description');
            });
        }
    }
};
