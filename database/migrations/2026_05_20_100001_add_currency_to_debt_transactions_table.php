<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('debt_transactions')) {
            return;
        }

        if (! Schema::hasColumn('debt_transactions', 'currency')) {
            Schema::table('debt_transactions', function (Blueprint $table) {
                $table->string('currency', 20)->default('شيكل')->after('amount');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('debt_transactions') && Schema::hasColumn('debt_transactions', 'currency')) {
            Schema::table('debt_transactions', function (Blueprint $table) {
                $table->dropColumn('currency');
            });
        }
    }
};
