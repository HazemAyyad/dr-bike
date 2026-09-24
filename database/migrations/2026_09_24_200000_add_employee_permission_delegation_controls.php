<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_details') &&
            ! Schema::hasColumn('employee_details', 'can_delegate_permissions')) {
            Schema::table('employee_details', function (Blueprint $table) {
                $table->boolean('can_delegate_permissions')
                    ->default(false)
                    ->index();
            });
        }

        if (! Schema::hasTable('employee_permission_audits') &&
            Schema::hasTable('users') &&
            Schema::hasTable('employee_details') &&
            Schema::hasTable('permissions')) {
            Schema::create('employee_permission_audits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('target_employee_id')->constrained('employee_details')->cascadeOnDelete();
                $table->foreignId('permission_id')->nullable()->constrained('permissions')->nullOnDelete();
                $table->string('action', 40);
                $table->json('metadata')->nullable();
                $table->string('ip_address', 64)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['target_employee_id', 'created_at'], 'employee_permission_audit_target_created_idx');
                $table->index(['actor_user_id', 'created_at'], 'employee_permission_audit_actor_created_idx');
            });
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive: permission audit history is retained.
    }
};
