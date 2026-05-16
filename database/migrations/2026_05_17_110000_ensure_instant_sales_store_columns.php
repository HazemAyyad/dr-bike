<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('instant_sales')) {
            return;
        }

        Schema::table('instant_sales', function (Blueprint $table) {
            if (! Schema::hasColumn('instant_sales', 'cost')) {
                $table->float('cost')->default(0)->after('product_id');
            }
            if (! Schema::hasColumn('instant_sales', 'buyer_type')) {
                $table->string('buyer_type', 20)->nullable()->after('type');
            }
            if (! Schema::hasColumn('instant_sales', 'buyer_id')) {
                $table->unsignedBigInteger('buyer_id')->nullable()->after('buyer_type');
            }
            if (! Schema::hasColumn('instant_sales', 'buyer_name')) {
                $table->string('buyer_name')->nullable()->after('buyer_id');
            }
            if (! Schema::hasColumn('instant_sales', 'buyer_phone')) {
                $table->string('buyer_phone', 50)->nullable()->after('buyer_name');
            }
            if (! Schema::hasColumn('instant_sales', 'buyer_address')) {
                $table->text('buyer_address')->nullable()->after('buyer_phone');
            }
        });
    }

    public function down(): void
    {
        // Intentionally left empty to avoid dropping production columns.
    }
};
