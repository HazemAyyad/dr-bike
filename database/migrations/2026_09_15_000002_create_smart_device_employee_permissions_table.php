<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('smart_device_employee_permissions')) {
            return;
        }

        Schema::create('smart_device_employee_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('smart_device_id')->constrained('smart_devices')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employee_details')->cascadeOnDelete();
            $table->boolean('can_view')->default(true);
            $table->boolean('can_control')->default(false);
            $table->boolean('can_schedule')->default(false);
            $table->timestamps();

            $table->unique(['smart_device_id', 'employee_id'], 'smart_device_employee_unique');
            $table->index(['employee_id', 'can_view'], 'smart_device_employee_view_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_device_employee_permissions');
    }
};
