<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_store_account_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->foreignId('seller_id')->nullable()->constrained('sellers')->restrictOnDelete();
            $table->string('role', 20);
            $table->string('account_source', 20);
            $table->string('status', 20)->default('pending');
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'role'], 'osal_user_role_unique');
            $table->unique('customer_id', 'osal_customer_unique');
            $table->unique('seller_id', 'osal_seller_unique');
            $table->index(['status', 'role'], 'osal_status_role_idx');
        });

        Schema::create('online_store_credit_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_link_id')->unique()->constrained('online_store_account_links')->restrictOnDelete();
            $table->boolean('is_eligible')->default(false);
            $table->decimal('credit_limit', 14, 2)->nullable();
            $table->string('currency', 3)->default('ILS');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('online_store_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->string('status', 20)->default('pending');
            $table->boolean('is_verified_purchase')->default(false);
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->text('moderation_reason')->nullable();
            $table->timestamps();
            $table->index(['product_id', 'status', 'created_at'], 'osr_product_status_created_idx');
            $table->index(['customer_id', 'created_at'], 'osr_customer_created_idx');
        });

        Schema::create('online_store_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->boolean('store_enabled')->default(false);
            $table->boolean('maintenance_mode')->default(false);
            $table->boolean('checkout_enabled')->default(false);
            $table->boolean('cod_enabled')->default(false);
            $table->boolean('guest_browsing_enabled')->default(true);
            $table->decimal('minimum_order', 14, 2)->default(0);
            $table->string('support_phone')->nullable();
            $table->string('whatsapp')->nullable();
            $table->json('enabled_languages');
            $table->json('cancellation_policy_translations')->nullable();
            $table->json('return_policy_translations')->nullable();
            $table->json('warranty_policy_translations')->nullable();
            $table->json('terms_translations')->nullable();
            $table->string('out_of_stock_behavior', 40)->default('visible_non_purchasable');
            $table->unsignedInteger('low_stock_threshold')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('online_store_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 50);
            $table->string('entity_type', 50);
            $table->unsignedBigInteger('entity_id');
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->string('request_id', 100)->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['entity_type', 'entity_id', 'occurred_at'], 'osae_entity_occurred_idx');
            $table->index(['actor_user_id', 'occurred_at'], 'osae_actor_occurred_idx');
            $table->index(['action', 'occurred_at'], 'osae_action_occurred_idx');
        });

        Schema::create('online_store_legacy_checkout_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('origin_user_id')->constrained('users')->restrictOnDelete();
            $table->char('request_fingerprint', 64);
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->restrictOnDelete();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['origin_user_id', 'request_fingerprint'], 'oslca_actor_fingerprint_unique');
            $table->index('expires_at', 'oslca_expires_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_store_legacy_checkout_attempts');
        Schema::dropIfExists('online_store_audit_events');
        Schema::dropIfExists('online_store_settings');
        Schema::dropIfExists('online_store_reviews');
        Schema::dropIfExists('online_store_credit_policies');
        Schema::dropIfExists('online_store_account_links');
    }
};
