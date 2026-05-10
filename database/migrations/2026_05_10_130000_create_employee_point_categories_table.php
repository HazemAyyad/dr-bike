<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_point_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('code')->unique();
            $table->enum('operation_type', ['add', 'deduct'])->index();
            $table->integer('default_points')->default(1);
            $table->boolean('is_active')->default(true)->index();
            $table->integer('sort_order')->default(0)->index();
            $table->timestamps();
        });

        $now = now();
        $defaults = [
            // positive
            ['name_ar' => 'الالتزام بالحضور', 'name_en' => 'Attendance commitment', 'code' => 'attendance_commitment', 'operation_type' => 'add', 'default_points' => 5, 'sort_order' => 10],
            ['name_ar' => 'الصلاة', 'name_en' => 'Prayer', 'code' => 'prayer', 'operation_type' => 'add', 'default_points' => 5, 'sort_order' => 20],
            ['name_ar' => 'المظهر', 'name_en' => 'Appearance', 'code' => 'appearance', 'operation_type' => 'add', 'default_points' => 3, 'sort_order' => 30],
            ['name_ar' => 'نظافة مكان العمل', 'name_en' => 'Workplace cleanliness', 'code' => 'workplace_cleanliness', 'operation_type' => 'add', 'default_points' => 5, 'sort_order' => 40],
            ['name_ar' => 'تقييم المدير', 'name_en' => 'Manager evaluation', 'code' => 'manager_evaluation', 'operation_type' => 'add', 'default_points' => 10, 'sort_order' => 50],
            ['name_ar' => 'مهام إضافية', 'name_en' => 'Extra tasks', 'code' => 'extra_tasks', 'operation_type' => 'add', 'default_points' => 10, 'sort_order' => 60],
            ['name_ar' => 'العمل الإضافي', 'name_en' => 'Overtime', 'code' => 'overtime', 'operation_type' => 'add', 'default_points' => 2, 'sort_order' => 70],
            // negative
            ['name_ar' => 'التأخّر', 'name_en' => 'Lateness', 'code' => 'lateness', 'operation_type' => 'deduct', 'default_points' => 5, 'sort_order' => 110],
            ['name_ar' => 'الغياب', 'name_en' => 'Absence', 'code' => 'absence', 'operation_type' => 'deduct', 'default_points' => 20, 'sort_order' => 120],
            ['name_ar' => 'مخالفة', 'name_en' => 'Violation', 'code' => 'violation', 'operation_type' => 'deduct', 'default_points' => 10, 'sort_order' => 130],
            ['name_ar' => 'سوء سلوك', 'name_en' => 'Bad behavior', 'code' => 'bad_behavior', 'operation_type' => 'deduct', 'default_points' => 10, 'sort_order' => 140],
            ['name_ar' => 'مخالفة المظهر', 'name_en' => 'Appearance violation', 'code' => 'appearance_violation', 'operation_type' => 'deduct', 'default_points' => 5, 'sort_order' => 150],
        ];

        foreach ($defaults as $row) {
            $row['is_active'] = true;
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            DB::table('employee_point_categories')->insertOrIgnore($row);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_point_categories');
    }
};
