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
            if (! Schema::hasColumn('instant_sales', 'additional_notes')) {
                $table->json('additional_notes')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('instant_sales') || ! Schema::hasColumn('instant_sales', 'additional_notes')) {
            return;
        }

        Schema::table('instant_sales', function (Blueprint $table) {
            $table->dropColumn('additional_notes');
        });
    }
};
