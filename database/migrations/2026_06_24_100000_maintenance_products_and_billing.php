<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance', function (Blueprint $table) {
            if (! Schema::hasColumn('maintenance', 'labor_cost')) {
                $table->decimal('labor_cost', 12, 2)->default(0)->after('description');
            }
            if (! Schema::hasColumn('maintenance', 'discount')) {
                $table->decimal('discount', 12, 2)->default(0)->after('labor_cost');
            }
            if (! Schema::hasColumn('maintenance', 'invoice_total')) {
                $table->decimal('invoice_total', 12, 2)->default(0)->after('discount');
            }
            if (! Schema::hasColumn('maintenance', 'paid_amount')) {
                $table->decimal('paid_amount', 12, 2)->default(0)->after('invoice_total');
            }
            if (! Schema::hasColumn('maintenance', 'payment_box_id')) {
                $table->unsignedBigInteger('payment_box_id')->nullable()->after('paid_amount');
            }
            if (! Schema::hasColumn('maintenance', 'instant_sale_id')) {
                $table->unsignedBigInteger('instant_sale_id')->nullable()->after('payment_box_id');
            }
        });

        if (! Schema::hasTable('maintenance_products')) {
            Schema::create('maintenance_products', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('maintenance_id');
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('size_id')->nullable();
                $table->unsignedBigInteger('size_color_id')->nullable();
                $table->unsignedInteger('quantity')->default(1);
                $table->decimal('unit_price', 12, 2)->default(0);
                $table->decimal('line_total', 12, 2)->default(0);
                $table->timestamps();

                $table->foreign('maintenance_id')
                    ->references('id')
                    ->on('maintenance')
                    ->onDelete('cascade');
                $table->foreign('product_id')
                    ->references('id')
                    ->on('products')
                    ->onDelete('cascade');
            });
        }

        if (Schema::hasTable('instant_sales') && ! Schema::hasColumn('instant_sales', 'maintenance_id')) {
            Schema::table('instant_sales', function (Blueprint $table) {
                $table->unsignedBigInteger('maintenance_id')->nullable()->after('sales_order_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_products');

        Schema::table('maintenance', function (Blueprint $table) {
            $columns = ['labor_cost', 'discount', 'invoice_total', 'paid_amount', 'payment_box_id', 'instant_sale_id'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('maintenance', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        if (Schema::hasTable('instant_sales') && Schema::hasColumn('instant_sales', 'maintenance_id')) {
            Schema::table('instant_sales', function (Blueprint $table) {
                $table->dropColumn('maintenance_id');
            });
        }
    }
};
