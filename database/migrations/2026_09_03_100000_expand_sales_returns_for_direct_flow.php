<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE sales_returns MODIFY sales_order_id BIGINT UNSIGNED NULL');
        }

        Schema::table('sales_returns', function (Blueprint $table) {
            $table->string('serial_number', 32)->nullable()->unique()->after('id');
            $table->foreignId('customer_id')->nullable()->after('instant_sale_id')->constrained('customers')->nullOnDelete();
            $table->foreignId('seller_id')->nullable()->after('customer_id')->constrained('sellers')->nullOnDelete();
            $table->string('status', 24)->default('completed')->after('return_type');
            $table->string('currency', 24)->default('شيكل')->after('total_amount');
            $table->decimal('cash_refund_amount', 14, 2)->default(0)->after('currency');
            $table->decimal('credit_amount', 14, 2)->default(0)->after('cash_refund_amount');
            $table->foreignId('refund_box_id')->nullable()->after('credit_amount')->constrained('boxes')->nullOnDelete();
            $table->foreignId('debt_transaction_id')->nullable()->after('refund_box_id')->constrained('debt_transactions')->nullOnDelete();
            $table->foreignId('sales_daily_session_id')->nullable()->after('debt_transaction_id')->constrained('sales_daily_sessions')->nullOnDelete();
            $table->timestamp('completed_at')->nullable()->after('note');

            $table->index(['customer_id', 'created_at']);
            $table->index(['seller_id', 'created_at']);
        });

        Schema::table('sales_return_items', function (Blueprint $table) {
            $table->foreignId('instant_sale_id')->nullable()->after('sales_order_item_id')->constrained('instant_sales')->nullOnDelete();
            $table->decimal('original_unit_price', 14, 2)->default(0)->after('unit_price');
            $table->decimal('inventory_unit_cost', 14, 4)->nullable()->after('original_unit_price');
            $table->decimal('inventory_total_cost', 14, 4)->nullable()->after('inventory_unit_cost');
            $table->text('price_override_reason')->nullable()->after('line_total');
            $table->index(['instant_sale_id', 'sales_return_id']);
        });
    }

    public function down(): void
    {
        $directIds = DB::table('sales_returns')->where('return_type', 'direct')->pluck('id');
        if ($directIds->isNotEmpty()) {
            DB::table('sales_return_items')->whereIn('sales_return_id', $directIds)->delete();
            DB::table('sales_returns')->whereIn('id', $directIds)->delete();
        }

        Schema::table('sales_return_items', function (Blueprint $table) {
            $table->dropIndex(['instant_sale_id', 'sales_return_id']);
            $table->dropForeign(['instant_sale_id']);
            $table->dropColumn(['instant_sale_id', 'original_unit_price', 'inventory_unit_cost', 'inventory_total_cost', 'price_override_reason']);
        });

        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropIndex(['customer_id', 'created_at']);
            $table->dropIndex(['seller_id', 'created_at']);
            $table->dropForeign(['customer_id']);
            $table->dropForeign(['seller_id']);
            $table->dropForeign(['refund_box_id']);
            $table->dropForeign(['debt_transaction_id']);
            $table->dropForeign(['sales_daily_session_id']);
            $table->dropUnique(['serial_number']);
            $table->dropColumn([
                'serial_number',
                'customer_id',
                'seller_id',
                'status',
                'currency',
                'cash_refund_amount',
                'credit_amount',
                'refund_box_id',
                'debt_transaction_id',
                'sales_daily_session_id',
                'completed_at',
            ]);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE sales_returns MODIFY sales_order_id BIGINT UNSIGNED NOT NULL');
        }
    }
};
