<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('logs') || Schema::hasColumn('logs', 'is_canceled')) {
            return;
        }

        Schema::table('logs', function (Blueprint $table) {
            $table->boolean('is_canceled')->default(false)->after('type');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('logs') || ! Schema::hasColumn('logs', 'is_canceled')) {
            return;
        }

        Schema::table('logs', function (Blueprint $table) {
            $table->dropColumn('is_canceled');
        });
    }
};
