<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('expenses')) {
            return;
        }

        Schema::table('expenses', function (Blueprint $table) {
            if (! Schema::hasColumn('expenses', 'box_id')) {
                $table->unsignedBigInteger('box_id')->nullable()->after('media')->index();
                $table->foreign('box_id')->references('id')->on('boxes')->nullOnDelete();
            }
        });

        Schema::table('expenses', function (Blueprint $table) {
            if (! Schema::hasColumn('expenses', 'expense_type')) {
                $table->string('expense_type', 32)->default('general')->after('name')->index();
            }
            if (! Schema::hasColumn('expenses', 'expense_date')) {
                $table->date('expense_date')->nullable()->after('price')->index();
            }
            if (! Schema::hasColumn('expenses', 'created_by_user_id')) {
                $table->unsignedBigInteger('created_by_user_id')->nullable()->after('box_id')->index();
                $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('expenses', 'employee_id')) {
                $table->unsignedBigInteger('employee_id')->nullable()->after('created_by_user_id')->index();
                $table->foreign('employee_id')->references('id')->on('employee_details')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        // Deliberately non-destructive: accounting history is retained on rollback.
    }
};
