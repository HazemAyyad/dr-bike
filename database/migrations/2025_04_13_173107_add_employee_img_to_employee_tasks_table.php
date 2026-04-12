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
        Schema::table('employee_tasks', function (Blueprint $table) {
            $table->string('admin_img')->nullable();
            $table->renameColumn('img', 'employee_img');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employee_tasks', function (Blueprint $table) {
            $table->renameColumn('employee_img', 'img');
            $table->dropColumn('admin_img');

        });
    }
};
