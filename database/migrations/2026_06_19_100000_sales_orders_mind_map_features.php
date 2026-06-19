<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_order_media') && ! Schema::hasColumn('sales_order_media', 'category')) {
            Schema::table('sales_order_media', function (Blueprint $table) {
                $table->string('category', 30)->default('general')->after('status_at_upload');
                $table->index(['sales_order_id', 'category'], 'so_media_order_category_idx');
            });
        }

        if (Schema::hasTable('sales_orders') && ! Schema::hasColumn('sales_orders', 'delivery_settled_box_id')) {
            Schema::table('sales_orders', function (Blueprint $table) {
                $table->foreignId('delivery_settled_box_id')
                    ->nullable()
                    ->after('delivery_settled_amount')
                    ->constrained('boxes')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sales_order_media') && Schema::hasColumn('sales_order_media', 'category')) {
            Schema::table('sales_order_media', function (Blueprint $table) {
                $table->dropIndex('so_media_order_category_idx');
                $table->dropColumn('category');
            });
        }

        if (Schema::hasTable('sales_orders') && Schema::hasColumn('sales_orders', 'delivery_settled_box_id')) {
            Schema::table('sales_orders', function (Blueprint $table) {
                $table->dropConstrainedForeignId('delivery_settled_box_id');
            });
        }
    }
};
