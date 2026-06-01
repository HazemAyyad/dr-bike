<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('profit_sales')) {
            return;
        }

        Schema::table('profit_sales', function (Blueprint $table) {
            if (! Schema::hasColumn('profit_sales', 'buyer_type')) {
                $table->string('buyer_type')->nullable()->after('video_path');
            }
            if (! Schema::hasColumn('profit_sales', 'customer_id')) {
                $table->foreignId('customer_id')->nullable()->after('buyer_type')->constrained('customers')->nullOnDelete();
            }
            if (! Schema::hasColumn('profit_sales', 'seller_id')) {
                $table->foreignId('seller_id')->nullable()->after('customer_id')->constrained('sellers')->nullOnDelete();
            }
            if (! Schema::hasColumn('profit_sales', 'buyer_name')) {
                $table->string('buyer_name')->nullable()->after('seller_id');
            }
            if (! Schema::hasColumn('profit_sales', 'payment_box_id')) {
                $table->foreignId('payment_box_id')->nullable()->after('buyer_name')->constrained('boxes')->nullOnDelete();
            }
            if (! Schema::hasColumn('profit_sales', 'payment_box_name')) {
                $table->string('payment_box_name')->nullable()->after('payment_box_id');
            }
            if (! Schema::hasColumn('profit_sales', 'payment_box_value')) {
                $table->decimal('payment_box_value', 12, 2)->default(0)->after('payment_box_name');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('profit_sales')) {
            return;
        }

        Schema::table('profit_sales', function (Blueprint $table) {
            foreach (['payment_box_value', 'payment_box_name', 'buyer_name', 'buyer_type'] as $column) {
                if (Schema::hasColumn('profit_sales', $column)) {
                    $table->dropColumn($column);
                }
            }
            if (Schema::hasColumn('profit_sales', 'payment_box_id')) {
                $table->dropConstrainedForeignId('payment_box_id');
            }
            if (Schema::hasColumn('profit_sales', 'seller_id')) {
                $table->dropConstrainedForeignId('seller_id');
            }
            if (Schema::hasColumn('profit_sales', 'customer_id')) {
                $table->dropConstrainedForeignId('customer_id');
            }
        });
    }
};
