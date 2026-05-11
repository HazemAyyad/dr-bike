<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_details')) {
            return;
        }

        if (! Schema::hasColumn('employee_details', 'deleted_at')) {
            Schema::table('employee_details', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('employee_details')) {
            return;
        }

        if (Schema::hasColumn('employee_details', 'deleted_at')) {
            Schema::table('employee_details', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
