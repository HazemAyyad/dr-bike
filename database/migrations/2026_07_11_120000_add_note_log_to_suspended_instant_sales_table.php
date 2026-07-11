<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('suspended_instant_sales')) {
            return;
        }

        Schema::table('suspended_instant_sales', function (Blueprint $table) {
            if (! Schema::hasColumn('suspended_instant_sales', 'note_log')) {
                $table->json('note_log')->nullable()->after('payload');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('suspended_instant_sales')) {
            return;
        }

        Schema::table('suspended_instant_sales', function (Blueprint $table) {
            if (Schema::hasColumn('suspended_instant_sales', 'note_log')) {
                $table->dropColumn('note_log');
            }
        });
    }
};
