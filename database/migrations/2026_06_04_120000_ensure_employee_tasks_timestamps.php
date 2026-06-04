<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_tasks')) {
            return;
        }

        Schema::table('employee_tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_tasks', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (! Schema::hasColumn('employee_tasks', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Non-destructive: keep columns if they were added.
    }
};
