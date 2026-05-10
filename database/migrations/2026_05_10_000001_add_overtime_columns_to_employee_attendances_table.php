<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_attendances', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_attendances', 'required_minutes')) {
                $table->integer('required_minutes')->default(0);
            }
            if (! Schema::hasColumn('employee_attendances', 'normal_minutes')) {
                $table->integer('normal_minutes')->default(0);
            }
            if (! Schema::hasColumn('employee_attendances', 'overtime_minutes')) {
                $table->integer('overtime_minutes')->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('employee_attendances', function (Blueprint $table) {
            if (Schema::hasColumn('employee_attendances', 'overtime_minutes')) {
                $table->dropColumn('overtime_minutes');
            }
            if (Schema::hasColumn('employee_attendances', 'normal_minutes')) {
                $table->dropColumn('normal_minutes');
            }
            if (Schema::hasColumn('employee_attendances', 'required_minutes')) {
                $table->dropColumn('required_minutes');
            }
        });
    }
};

