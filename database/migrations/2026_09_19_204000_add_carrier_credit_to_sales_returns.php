<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_returns') && ! Schema::hasColumn('sales_returns', 'carrier_credit_amount')) {
            Schema::table('sales_returns', function (Blueprint $table) {
                $table->decimal('carrier_credit_amount', 14, 4)->default(0)->after('credit_amount');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sales_returns') && Schema::hasColumn('sales_returns', 'carrier_credit_amount')) {
            Schema::table('sales_returns', function (Blueprint $table) {
                $table->dropColumn('carrier_credit_amount');
            });
        }
    }
};
