<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_store_promotions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('description_translations')->nullable();
            $table->string('discount_type', 20);
            $table->decimal('discount_value', 14, 2);
            $table->string('applies_to', 20)->default('both');
            $table->string('scope', 20);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('priority')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'starts_at', 'ends_at'], 'osp_active_window_idx');
            $table->index(['scope', 'is_active'], 'osp_scope_active_idx');
            $table->index(['applies_to', 'priority'], 'osp_applies_priority_idx');
        });

        Schema::create('online_store_promotion_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained('online_store_promotions')->cascadeOnDelete();
            $table->string('target_type', 20);
            $table->unsignedBigInteger('target_id');
            $table->timestamps();
            $table->unique(['promotion_id', 'target_type', 'target_id'], 'ospt_promotion_target_unique');
            $table->index(['target_type', 'target_id'], 'ospt_target_idx');
        });

        Schema::create('online_store_coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('discount_type', 20);
            $table->decimal('discount_value', 14, 2);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->decimal('minimum_order', 14, 2)->default(0);
            $table->unsignedInteger('total_usage_limit')->nullable();
            $table->unsignedInteger('per_user_usage_limit')->nullable();
            $table->string('eligible_account_type', 20)->default('both');
            $table->string('applies_to', 20)->default('both');
            $table->boolean('is_active')->default(false);
            $table->string('scope', 20);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['is_active', 'starts_at', 'ends_at'], 'oscoupon_active_window_idx');
        });

        Schema::create('online_store_coupon_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('online_store_coupons')->cascadeOnDelete();
            $table->string('target_type', 20);
            $table->unsignedBigInteger('target_id');
            $table->timestamps();
            $table->unique(['coupon_id', 'target_type', 'target_id'], 'osct_coupon_target_unique');
            $table->index(['target_type', 'target_id'], 'osct_target_idx');
        });

        Schema::create('online_store_coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('online_store_coupons')->restrictOnDelete();
            $table->foreignId('sales_order_id')->unique()->constrained('sales_orders')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->foreignId('seller_id')->nullable()->constrained('sellers')->restrictOnDelete();
            $table->decimal('discount_amount', 14, 2);
            $table->string('status', 20)->default('reserved');
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->index(['coupon_id', 'status'], 'oscr_coupon_status_idx');
            $table->index(['coupon_id', 'user_id', 'status'], 'oscr_coupon_user_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_store_coupon_redemptions');
        Schema::dropIfExists('online_store_coupon_targets');
        Schema::dropIfExists('online_store_coupons');
        Schema::dropIfExists('online_store_promotion_targets');
        Schema::dropIfExists('online_store_promotions');
    }
};
