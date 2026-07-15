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
            if (! Schema::hasColumn('instant_sales', 'sale_kind')) {
                $table->string('sale_kind', 40)->default('regular')->after('type')->index();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('instant_sales') || ! Schema::hasColumn('instant_sales', 'sale_kind')) {
            return;
        }

        Schema::table('instant_sales', function (Blueprint $table) {
            $table->dropColumn('sale_kind');
        });
    }
};
