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
            if (! Schema::hasColumn('instant_sales', 'serial_number')) {
                $table->string('serial_number', 32)->nullable()->after('id');
                $table->index('serial_number');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('instant_sales')) {
            return;
        }

        Schema::table('instant_sales', function (Blueprint $table) {
            if (Schema::hasColumn('instant_sales', 'serial_number')) {
                $table->dropIndex(['serial_number']);
                $table->dropColumn('serial_number');
            }
        });
    }
};
