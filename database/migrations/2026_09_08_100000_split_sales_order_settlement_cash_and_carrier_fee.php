<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_order_settlements')) {
            Schema::table('sales_order_settlements', function (Blueprint $table) {
                if (! Schema::hasColumn('sales_order_settlements', 'cash_amount')) {
                    $table->decimal('cash_amount', 14, 2)->nullable()->after('amount');
                }
                if (! Schema::hasColumn('sales_order_settlements', 'carrier_fee')) {
                    $table->decimal('carrier_fee', 14, 2)->default(0)->after('cash_amount');
                }
                if (! Schema::hasColumn('sales_order_settlements', 'carrier_fee_expense_id')) {
                    $table->unsignedBigInteger('carrier_fee_expense_id')->nullable()->after('carrier_fee')->index();
                    $table->foreign('carrier_fee_expense_id', 'sales_order_settlements_fee_expense_fk')
                        ->references('id')->on('expenses')->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('delivery_company_settlement_batches')) {
            Schema::table('delivery_company_settlement_batches', function (Blueprint $table) {
                if (! Schema::hasColumn('delivery_company_settlement_batches', 'cash_amount')) {
                    $table->decimal('cash_amount', 14, 2)->nullable()->after('amount');
                }
                if (! Schema::hasColumn('delivery_company_settlement_batches', 'carrier_fee')) {
                    $table->decimal('carrier_fee', 14, 2)->default(0)->after('cash_amount');
                }
            });
        }
    }

    public function down(): void
    {
        // Accounting history is deliberately retained on rollback.
    }
};
