<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('store_sections', 'description')) {
            Schema::table('store_sections', function (Blueprint $table) {
                $table->text('description')->nullable()->after('name');
            });
        }
    }

    public function down(): void
    {
        Schema::table('store_sections', function (Blueprint $table) {
            if (Schema::hasColumn('store_sections', 'description')) {
                $table->dropColumn('description');
            }
        });
    }
};
