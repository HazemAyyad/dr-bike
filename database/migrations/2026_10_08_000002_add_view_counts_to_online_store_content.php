<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_store_listings', function (Blueprint $table) {
            $table->unsignedBigInteger('view_count')->default(0)->after('online_stock_limit');
        });

        Schema::table('online_store_home_sections', function (Blueprint $table) {
            $table->unsignedBigInteger('view_count')->default(0)->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('online_store_home_sections', function (Blueprint $table) {
            $table->dropColumn('view_count');
        });

        Schema::table('online_store_listings', function (Blueprint $table) {
            $table->dropColumn('view_count');
        });
    }
};
