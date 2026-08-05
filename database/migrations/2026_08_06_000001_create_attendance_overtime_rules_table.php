<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attendance_overtime_rules')) {
            return;
        }

        Schema::create('attendance_overtime_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('grace_minutes')->default(15);
            $table->date('effective_from');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique('effective_from');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_overtime_rules');
    }
};
