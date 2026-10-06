<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_store_listings', function (Blueprint $table) {
            $table->unsignedInteger('online_stock_limit')->nullable()->after('show_as_offer');
        });
    }

    public function down(): void
    {
        Schema::table('online_store_listings', function (Blueprint $table) {
            $table->dropColumn('online_stock_limit');
        });
    }
};
