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
            if (! Schema::hasColumn('instant_sales', 'size_id')) {
                $table->unsignedBigInteger('size_id')->nullable()->after('product_id');
                $table->foreign('size_id')->references('id')->on('sizes')->nullOnDelete();
            }
            if (! Schema::hasColumn('instant_sales', 'size_color_id')) {
                $table->unsignedBigInteger('size_color_id')->nullable()->after('size_id');
                $table->foreign('size_color_id')->references('id')->on('size_colors')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('instant_sales')) {
            return;
        }

        Schema::table('instant_sales', function (Blueprint $table) {
            if (Schema::hasColumn('instant_sales', 'size_color_id')) {
                $table->dropForeign(['size_color_id']);
                $table->dropColumn('size_color_id');
            }
            if (Schema::hasColumn('instant_sales', 'size_id')) {
                $table->dropForeign(['size_id']);
                $table->dropColumn('size_id');
            }
        });
    }
};
