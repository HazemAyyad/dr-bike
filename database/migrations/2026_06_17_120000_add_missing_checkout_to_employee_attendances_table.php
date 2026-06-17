<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_attendances')) {
            return;
        }

        Schema::table('employee_attendances', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_attendances', 'missing_checkout')) {
                $table->boolean('missing_checkout')
                    ->default(false)
                    ->index()
                    ->after('left_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('employee_attendances')) {
            return;
        }

        Schema::table('employee_attendances', function (Blueprint $table) {
            if (Schema::hasColumn('employee_attendances', 'missing_checkout')) {
                $table->dropColumn('missing_checkout');
            }
        });
    }
};

