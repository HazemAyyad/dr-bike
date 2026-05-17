<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('offer_packages')) {
            return;
        }

        if (! Schema::hasColumn('offer_packages', 'package_quantity')) {
            Schema::table('offer_packages', function (Blueprint $table) {
                $table->unsignedInteger('package_quantity')->default(1)->after('price');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('offer_packages') && Schema::hasColumn('offer_packages', 'package_quantity')) {
            Schema::table('offer_packages', function (Blueprint $table) {
                $table->dropColumn('package_quantity');
            });
        }
    }
};
