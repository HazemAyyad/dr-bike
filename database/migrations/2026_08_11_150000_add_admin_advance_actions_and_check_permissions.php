<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $permissions = [
        ['name' => 'مشاهدة الشيكات الواردة', 'name_en' => 'Checks Incoming View'],
        ['name' => 'مشاهدة الشيكات الصادرة', 'name_en' => 'Checks Outgoing View'],
        ['name' => 'إضافة شيك وارد', 'name_en' => 'Checks Incoming Create'],
        ['name' => 'إضافة شيك صادر', 'name_en' => 'Checks Outgoing Create'],
    ];

    public function up(): void
    {
        if (Schema::hasTable('employee_orders')) {
            Schema::table('employee_orders', function (Blueprint $table) {
                if (! Schema::hasColumn('employee_orders', 'cancelled_by')) {
                    $table->unsignedBigInteger('cancelled_by')->nullable()->after('box_log_id');
                    $table->index('cancelled_by');
                }
                if (! Schema::hasColumn('employee_orders', 'cancelled_at')) {
                    $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
                }
                if (! Schema::hasColumn('employee_orders', 'cancellation_reason')) {
                    $table->text('cancellation_reason')->nullable()->after('cancelled_at');
                }
                if (! Schema::hasColumn('employee_orders', 'edited_after_approval_by')) {
                    $table->unsignedBigInteger('edited_after_approval_by')->nullable()->after('cancellation_reason');
                    $table->index('edited_after_approval_by');
                }
                if (! Schema::hasColumn('employee_orders', 'edited_after_approval_at')) {
                    $table->timestamp('edited_after_approval_at')->nullable()->after('edited_after_approval_by');
                }
                if (! Schema::hasColumn('employee_orders', 'previous_loan_value')) {
                    $table->decimal('previous_loan_value', 12, 2)->nullable()->after('edited_after_approval_at');
                }
            });
        }

        if (! Schema::hasTable('permissions')) {
            return;
        }

        foreach ($this->permissions as $permission) {
            $exists = DB::table('permissions')->where('name_en', $permission['name_en'])->exists();
            if ($exists) {
                DB::table('permissions')
                    ->where('name_en', $permission['name_en'])
                    ->update(['name' => $permission['name'], 'updated_at' => now()]);
                continue;
            }

            DB::table('permissions')->insert([
                'name' => $permission['name'],
                'name_en' => $permission['name_en'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            foreach ($this->permissions as $permission) {
                DB::table('permissions')->where('name_en', $permission['name_en'])->delete();
            }
        }

        if (! Schema::hasTable('employee_orders')) {
            return;
        }

        Schema::table('employee_orders', function (Blueprint $table) {
            foreach ([
                'previous_loan_value',
                'edited_after_approval_at',
                'edited_after_approval_by',
                'cancellation_reason',
                'cancelled_at',
                'cancelled_by',
            ] as $column) {
                if (Schema::hasColumn('employee_orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
