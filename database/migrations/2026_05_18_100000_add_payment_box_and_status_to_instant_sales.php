<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instant_sales', function (Blueprint $table) {
            if (! Schema::hasColumn('instant_sales', 'payment_box_id')) {
                $table->unsignedBigInteger('payment_box_id')->nullable()->after('buyer_address');
                $table->foreign('payment_box_id')
                    ->references('id')
                    ->on('boxes')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('instant_sales', 'payment_box_name')) {
                $table->string('payment_box_name')
                    ->nullable()
                    ->charset('utf8mb4')
                    ->collation('utf8mb4_unicode_ci')
                    ->after('payment_box_id');
            }
            if (! Schema::hasColumn('instant_sales', 'payment_box_value')) {
                $table->decimal('payment_box_value', 15, 2)->nullable()->after('payment_box_name');
            }
            if (! Schema::hasColumn('instant_sales', 'status')) {
                $table->string('status', 20)->default('active')->after('payment_box_value');
            }
            if (! Schema::hasColumn('instant_sales', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('instant_sales', function (Blueprint $table) {
            if (Schema::hasColumn('instant_sales', 'payment_box_id')) {
                $table->dropForeign(['payment_box_id']);
            }
            foreach (['cancelled_at', 'status', 'payment_box_value', 'payment_box_name', 'payment_box_id'] as $col) {
                if (Schema::hasColumn('instant_sales', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
