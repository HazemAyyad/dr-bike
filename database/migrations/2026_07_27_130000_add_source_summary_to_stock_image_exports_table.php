<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_image_exports', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_image_exports', 'source_summary')) {
                $table->json('source_summary')->nullable()->after('filters');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stock_image_exports', function (Blueprint $table) {
            if (Schema::hasColumn('stock_image_exports', 'source_summary')) {
                $table->dropColumn('source_summary');
            }
        });
    }
};
