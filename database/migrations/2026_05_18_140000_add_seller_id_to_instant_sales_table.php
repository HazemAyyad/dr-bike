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

        if (Schema::hasColumn('instant_sales', 'seller_id')) {
            return;
        }

        Schema::table('instant_sales', function (Blueprint $table) {
            $table->foreignId('seller_id')
                ->nullable()
                ->after('buyer_id')
                ->constrained('sellers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('instant_sales') && Schema::hasColumn('instant_sales', 'seller_id')) {
            Schema::table('instant_sales', function (Blueprint $table) {
                $table->dropForeign(['seller_id']);
                $table->dropColumn('seller_id');
            });
        }
    }
};
