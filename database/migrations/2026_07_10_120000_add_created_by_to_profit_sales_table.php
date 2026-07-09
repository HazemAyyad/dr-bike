<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('profit_sales') || Schema::hasColumn('profit_sales', 'created_by')) {
            return;
        }

        Schema::table('profit_sales', function (Blueprint $table) {
            $table->foreignId('created_by')
                ->nullable()
                ->after('sales_daily_session_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('profit_sales') || ! Schema::hasColumn('profit_sales', 'created_by')) {
            return;
        }

        Schema::table('profit_sales', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropColumn('created_by');
        });
    }
};
