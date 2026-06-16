<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_order_items')) {
            Schema::table('sales_order_items', function (Blueprint $table) {
                if (! Schema::hasColumn('sales_order_items', 'delivered_qty')) {
                    $table->unsignedInteger('delivered_qty')->default(0)->after('dispatched_qty');
                }
                if (! Schema::hasColumn('sales_order_items', 'returned_qty')) {
                    $table->unsignedInteger('returned_qty')->default(0)->after('delivered_qty');
                }
            });
        }

        if (! Schema::hasTable('sales_returns')) {
            Schema::create('sales_returns', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
                $table->string('return_type', 40)->default('partial');
                $table->foreignId('instant_sale_id')->nullable()->constrained('instant_sales')->nullOnDelete();
                $table->decimal('total_amount', 14, 2)->default(0);
                $table->text('note')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['sales_order_id', 'return_type']);
            });
        }

        if (! Schema::hasTable('sales_return_items')) {
            Schema::create('sales_return_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sales_return_id')->constrained('sales_returns')->cascadeOnDelete();
                $table->foreignId('sales_order_item_id')->nullable()->constrained('sales_order_items')->nullOnDelete();
                $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
                $table->unsignedBigInteger('size_id')->nullable();
                $table->unsignedBigInteger('size_color_id')->nullable();
                $table->string('product_name')->nullable();
                $table->unsignedInteger('quantity')->default(1);
                $table->decimal('unit_price', 14, 2)->default(0);
                $table->decimal('line_total', 14, 2)->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_return_items');
        Schema::dropIfExists('sales_returns');

        if (Schema::hasTable('sales_order_items')) {
            Schema::table('sales_order_items', function (Blueprint $table) {
                if (Schema::hasColumn('sales_order_items', 'returned_qty')) {
                    $table->dropColumn('returned_qty');
                }
                if (Schema::hasColumn('sales_order_items', 'delivered_qty')) {
                    $table->dropColumn('delivered_qty');
                }
            });
        }
    }
};
