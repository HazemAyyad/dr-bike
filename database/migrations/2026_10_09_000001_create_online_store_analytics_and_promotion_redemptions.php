<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_store_promotion_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained('online_store_promotions')->restrictOnDelete();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->foreignId('seller_id')->nullable()->constrained('sellers')->restrictOnDelete();
            $table->unsignedInteger('items_count')->default(0);
            $table->unsignedInteger('quantity')->default(0);
            $table->decimal('discount_amount', 14, 2);
            $table->timestamp('used_at');
            $table->timestamps();

            $table->unique(['promotion_id', 'sales_order_id'], 'ospr_promotion_order_unique');
            $table->index(['promotion_id', 'used_at'], 'ospr_promotion_used_idx');
            $table->index(['user_id', 'used_at'], 'ospr_user_used_idx');
        });

        Schema::create('online_store_daily_metrics', function (Blueprint $table) {
            $table->date('metric_date')->primary();
            $table->unsignedBigInteger('store_visits')->default(0);
            $table->unsignedBigInteger('product_views')->default(0);
            $table->unsignedBigInteger('section_views')->default(0);
            $table->unsignedBigInteger('banner_clicks')->default(0);
            $table->timestamps();
        });

        Schema::create('online_store_daily_visitors', function (Blueprint $table) {
            $table->id();
            $table->date('visit_date');
            $table->char('visitor_hash', 64);
            $table->timestamps();

            $table->unique(['visit_date', 'visitor_hash'], 'osdv_date_visitor_unique');
            $table->index('visit_date', 'osdv_visit_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_store_daily_visitors');
        Schema::dropIfExists('online_store_daily_metrics');
        Schema::dropIfExists('online_store_promotion_redemptions');
    }
};
