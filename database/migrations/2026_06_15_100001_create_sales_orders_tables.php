<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_orders')) {
            Schema::create('sales_orders', function (Blueprint $table) {
                $table->id();
                $table->string('serial_number', 32)->nullable()->unique();
                $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
                $table->string('customer_name')->nullable();
                $table->string('customer_phone', 50)->nullable();
                $table->string('customer_address', 500)->nullable();
                $table->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete();
                $table->string('status', 40)->default('unconfirmed');
                $table->foreignId('parent_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
                $table->foreignId('root_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
                $table->string('payment_type', 20)->default('cash');
                $table->foreignId('payment_box_id')->nullable()->constrained('boxes')->nullOnDelete();
                $table->decimal('payment_amount', 14, 2)->default(0);
                $table->foreignId('delivery_company_id')->nullable()->constrained('delivery_companies')->nullOnDelete();
                $table->string('delivery_company_name')->nullable();
                $table->decimal('customer_delivery_fee', 14, 2)->default(0);
                $table->decimal('carrier_delivery_cost', 14, 2)->nullable();
                $table->decimal('subtotal', 14, 2)->default(0);
                $table->decimal('discount', 14, 2)->default(0);
                $table->decimal('total', 14, 2)->default(0);
                $table->foreignId('debt_id')->nullable()->constrained('debts')->nullOnDelete();
                $table->foreignId('instant_sale_id')->nullable()->constrained('instant_sales')->nullOnDelete();
                $table->timestamp('hidden_until')->nullable();
                $table->timestamp('postponed_until')->nullable();
                $table->string('postpone_reason', 500)->nullable();
                $table->boolean('is_debt_collection')->default(false);
                $table->timestamp('delivery_settled_at')->nullable();
                $table->decimal('delivery_settled_amount', 14, 2)->nullable();
                $table->timestamp('stock_deducted_at')->nullable();
                $table->timestamp('financial_posted_at')->nullable();
                $table->timestamp('archived_at')->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['status', 'created_at']);
                $table->index('customer_id');
                $table->index('parent_order_id');
            });
        }

        if (! Schema::hasTable('sales_order_packages')) {
            Schema::create('sales_order_packages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
                $table->unsignedTinyInteger('package_index')->default(1);
                $table->string('status', 40)->default('pending');
                $table->decimal('customer_delivery_fee', 14, 2)->default(0);
                $table->string('tracking_number', 100)->nullable();
                $table->timestamps();

                $table->unique(['sales_order_id', 'package_index']);
            });
        }

        if (! Schema::hasTable('sales_order_items')) {
            Schema::create('sales_order_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
                $table->foreignId('sales_order_package_id')->nullable()->constrained('sales_order_packages')->nullOnDelete();
                $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
                $table->unsignedBigInteger('size_id')->nullable();
                $table->unsignedBigInteger('size_color_id')->nullable();
                $table->string('product_name')->nullable();
                $table->unsignedInteger('quantity')->default(1);
                $table->unsignedInteger('reserved_qty')->default(0);
                $table->unsignedInteger('dispatched_qty')->default(0);
                $table->decimal('unit_price', 14, 2)->default(0);
                $table->decimal('line_total', 14, 2)->default(0);
                $table->boolean('is_hidden')->default(false);
                $table->timestamps();

                $table->index(['sales_order_id', 'product_id']);
            });
        }

        if (! Schema::hasTable('sales_order_status_logs')) {
            Schema::create('sales_order_status_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
                $table->string('from_status', 40)->nullable();
                $table->string('to_status', 40);
                $table->text('note')->nullable();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('sales_order_media')) {
            Schema::create('sales_order_media', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
                $table->string('status_at_upload', 40)->nullable();
                $table->string('type', 20)->default('image');
                $table->string('path');
                $table->string('mime', 100)->nullable();
                $table->unsignedInteger('size_bytes')->nullable();
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('sales_order_deliveries')) {
            Schema::create('sales_order_deliveries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
                $table->foreignId('sales_order_package_id')->nullable()->constrained('sales_order_packages')->nullOnDelete();
                $table->foreignId('delivery_company_id')->nullable()->constrained('delivery_companies')->nullOnDelete();
                $table->string('delivery_company_name')->nullable();
                $table->string('tracking_number', 100)->nullable();
                $table->string('external_reference', 100)->nullable();
                $table->timestamp('handed_over_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_deliveries');
        Schema::dropIfExists('sales_order_media');
        Schema::dropIfExists('sales_order_status_logs');
        Schema::dropIfExists('sales_order_items');
        Schema::dropIfExists('sales_order_packages');
        Schema::dropIfExists('sales_orders');
    }
};
