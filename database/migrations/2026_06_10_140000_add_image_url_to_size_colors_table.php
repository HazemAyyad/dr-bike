<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('size_colors')) {
            return;
        }

        Schema::table('size_colors', function (Blueprint $table) {
            if (! Schema::hasColumn('size_colors', 'image_url')) {
                $table->string('image_url', 500)->nullable()->after('stock');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('size_colors') || ! Schema::hasColumn('size_colors', 'image_url')) {
            return;
        }

        Schema::table('size_colors', function (Blueprint $table) {
            $table->dropColumn('image_url');
        });
    }
};
