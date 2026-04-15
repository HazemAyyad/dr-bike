<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('special_tasks', function (Blueprint $table) {
            if (Schema::hasColumn('special_tasks', 'shown_for_employee')
                && ! Schema::hasColumn('special_tasks', 'not_shown_for_employee')) {
                $table->renameColumn('shown_for_employee', 'not_shown_for_employee');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('special_tasks', function (Blueprint $table) {
            if (Schema::hasColumn('special_tasks', 'not_shown_for_employee')
                && ! Schema::hasColumn('special_tasks', 'shown_for_employee')) {
                $table->renameColumn('not_shown_for_employee', 'shown_for_employee');
            }
        });
    }
};
