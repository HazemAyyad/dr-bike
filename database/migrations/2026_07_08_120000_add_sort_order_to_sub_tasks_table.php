<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sub_tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('sub_tasks', 'sort_order')) {
                $table->unsignedInteger('sort_order')->default(0)->after('special_task_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sub_tasks', function (Blueprint $table) {
            if (Schema::hasColumn('sub_tasks', 'sort_order')) {
                $table->dropColumn('sort_order');
            }
        });
    }
};
