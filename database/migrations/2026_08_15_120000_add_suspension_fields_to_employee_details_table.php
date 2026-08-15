<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_details', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_details', 'is_suspended')) {
                $table->boolean('is_suspended')->default(false)->after('user_id')->index();
            }
            if (! Schema::hasColumn('employee_details', 'suspended_at')) {
                $table->timestamp('suspended_at')->nullable()->after('is_suspended');
            }
            if (! Schema::hasColumn('employee_details', 'suspended_by')) {
                $table->foreignId('suspended_by')->nullable()->after('suspended_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('employee_details', 'suspension_reason')) {
                $table->string('suspension_reason', 500)->nullable()->after('suspended_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employee_details', function (Blueprint $table) {
            if (Schema::hasColumn('employee_details', 'suspended_by')) {
                $table->dropConstrainedForeignId('suspended_by');
            }
            foreach (['suspension_reason', 'suspended_at', 'is_suspended'] as $column) {
                if (Schema::hasColumn('employee_details', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
