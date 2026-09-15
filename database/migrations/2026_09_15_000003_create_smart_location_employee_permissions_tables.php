<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smart_home_employee_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('smart_home_id')->constrained('smart_homes')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employee_details')->cascadeOnDelete();
            $table->boolean('can_view')->default(true);
            $table->boolean('can_control')->default(false);
            $table->boolean('can_schedule')->default(false);
            $table->timestamps();
            $table->unique(['smart_home_id', 'employee_id'], 'smart_home_employee_unique');
        });

        Schema::create('smart_room_employee_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('smart_room_id')->constrained('smart_rooms')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employee_details')->cascadeOnDelete();
            $table->boolean('can_view')->default(true);
            $table->boolean('can_control')->default(false);
            $table->boolean('can_schedule')->default(false);
            $table->timestamps();
            $table->unique(['smart_room_id', 'employee_id'], 'smart_room_employee_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_room_employee_permissions');
        Schema::dropIfExists('smart_home_employee_permissions');
    }
};
