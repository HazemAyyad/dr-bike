<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('size_colors') || ! Schema::hasColumn('size_colors', 'stock')) {
            return;
        }

        Schema::table('size_colors', function (Blueprint $table) {
            $table->integer('stock')->default(0)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('size_colors') || ! Schema::hasColumn('size_colors', 'stock')) {
            return;
        }

        Schema::table('size_colors', function (Blueprint $table) {
            $table->unsignedInteger('stock')->default(0)->change();
        });
    }
};
