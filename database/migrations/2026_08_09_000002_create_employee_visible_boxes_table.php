<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_visible_boxes')) {
            Schema::create('employee_visible_boxes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('employee_id');
                $table->unsignedBigInteger('box_id');
                $table->timestamps();

                $table->unique(['employee_id', 'box_id']);
                $table->foreign('employee_id')->references('id')->on('employee_details')->cascadeOnDelete();
                $table->foreign('box_id')->references('id')->on('boxes')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('employee_permissions') || ! Schema::hasTable('permissions') || ! Schema::hasTable('boxes')) {
            return;
        }

        $boxPermissionIds = DB::table('permissions')
            ->whereIn('name_en', ['Boxes Section', 'Daily Boxes'])
            ->pluck('id');

        if ($boxPermissionIds->isEmpty()) {
            return;
        }

        $employeeIds = DB::table('employee_permissions')
            ->join('employee_details', 'employee_permissions.employee_id', '=', 'employee_details.id')
            ->whereIn('permission_id', $boxPermissionIds)
            ->pluck('employee_permissions.employee_id')
            ->unique()
            ->values();

        $boxIds = DB::table('boxes')->pluck('id');

        foreach ($employeeIds as $employeeId) {
            foreach ($boxIds as $boxId) {
                DB::table('employee_visible_boxes')->updateOrInsert(
                    ['employee_id' => $employeeId, 'box_id' => $boxId],
                    ['created_at' => now(), 'updated_at' => now()]
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_visible_boxes');
    }
};
