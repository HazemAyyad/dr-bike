<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('profit_sales')) {
            return;
        }

        DB::statement('ALTER TABLE profit_sales CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        Schema::table('profit_sales', function (Blueprint $table) {
            if (! Schema::hasColumn('profit_sales', 'status')) {
                $table->string('status')->nullable()->default('active')->after('payment_box_value');
            }
            if (! Schema::hasColumn('profit_sales', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('profit_sales')) {
            return;
        }

        Schema::table('profit_sales', function (Blueprint $table) {
            if (Schema::hasColumn('profit_sales', 'cancelled_at')) {
                $table->dropColumn('cancelled_at');
            }
            if (Schema::hasColumn('profit_sales', 'status')) {
                $table->dropColumn('status');
            }
        });
    }
};
