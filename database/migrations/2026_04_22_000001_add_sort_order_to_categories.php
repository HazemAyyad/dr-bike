<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('categories', 'sortOrder')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->integer('sortOrder')->default(0)->after('isShow');
            });
        }
        if (!Schema::hasColumn('sub_categories', 'sortOrder')) {
            Schema::table('sub_categories', function (Blueprint $table) {
                $table->integer('sortOrder')->default(0)->after('isShow');
            });
        }
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            if (Schema::hasColumn('categories', 'sortOrder')) {
                $table->dropColumn('sortOrder');
            }
        });
        Schema::table('sub_categories', function (Blueprint $table) {
            if (Schema::hasColumn('sub_categories', 'sortOrder')) {
                $table->dropColumn('sortOrder');
            }
        });
    }
};
