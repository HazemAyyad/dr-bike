<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('employee_details', 'points')) {
            return;
        }

        Schema::table('employee_details', function (Blueprint $table) {
            $table->dropColumn('points');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('employee_details', 'points')) {
            return;
        }

        Schema::table('employee_details', function (Blueprint $table) {
            $table->integer('points')->default(0);
        });
    }
};
