<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('returns')) {
            Schema::table('returns', function (Blueprint $table) {
                if (! Schema::hasColumn('returns', 'bill_id')) {
                    $table->unsignedBigInteger('bill_id')->nullable()->after('seller_id');
                }
                if (! Schema::hasColumn('returns', 'customer_id')) {
                    $table->unsignedBigInteger('customer_id')->nullable()->after('seller_id');
                }
                if (! Schema::hasColumn('returns', 'currency')) {
                    $table->string('currency', 20)->default('شيكل')->after('total');
                }
                if (! Schema::hasColumn('returns', 'resolution')) {
                    $table->string('resolution', 40)->default('supplier_credit')->after('status');
                }
                if (! Schema::hasColumn('returns', 'refund_box_id')) {
                    $table->unsignedBigInteger('refund_box_id')->nullable()->after('resolution');
                }
                if (! Schema::hasColumn('returns', 'debt_transaction_id')) {
                    $table->unsignedBigInteger('debt_transaction_id')->nullable()->after('refund_box_id');
                }
                if (! Schema::hasColumn('returns', 'note')) {
                    $table->text('note')->nullable()->after('debt_transaction_id');
                }
                if (! Schema::hasColumn('returns', 'created_by')) {
                    $table->unsignedBigInteger('created_by')->nullable()->after('note');
                }
                if (! Schema::hasColumn('returns', 'delivered_at')) {
                    $table->timestamp('delivered_at')->nullable()->after('created_by');
                }
            });
        }

        if (Schema::hasTable('purchase_returns')) {
            Schema::table('purchase_returns', function (Blueprint $table) {
                if (! Schema::hasColumn('purchase_returns', 'bill_id')) {
                    $table->unsignedBigInteger('bill_id')->nullable()->after('return_id');
                }
                if (! Schema::hasColumn('purchase_returns', 'bill_item_id')) {
                    $table->unsignedBigInteger('bill_item_id')->nullable()->after('bill_id');
                }
                if (! Schema::hasColumn('purchase_returns', 'size_id')) {
                    $table->unsignedBigInteger('size_id')->nullable()->after('product_id');
                }
                if (! Schema::hasColumn('purchase_returns', 'size_color_id')) {
                    $table->unsignedBigInteger('size_color_id')->nullable()->after('size_id');
                }
                if (! Schema::hasColumn('purchase_returns', 'cost_total')) {
                    $table->decimal('cost_total', 14, 6)->default(0)->after('quantity');
                }
                if (! Schema::hasColumn('purchase_returns', 'note')) {
                    $table->text('note')->nullable()->after('cost_total');
                }
            });
        }
    }

    public function down(): void
    {
        // Additive production migration. Columns are intentionally preserved on rollback.
    }
};
