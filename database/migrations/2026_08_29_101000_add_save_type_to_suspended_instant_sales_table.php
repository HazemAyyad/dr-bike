<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('suspended_instant_sales')
            || Schema::hasColumn('suspended_instant_sales', 'save_type')) {
            return;
        }

        Schema::table('suspended_instant_sales', function (Blueprint $table) {
            $table->string('save_type', 16)
                ->default('manual')
                ->after('current_step');
            $table->index(
                ['save_type', 'status', 'created_by_user_id'],
                'suspended_sales_type_status_user_index'
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('suspended_instant_sales')
            || ! Schema::hasColumn('suspended_instant_sales', 'save_type')) {
            return;
        }

        Schema::table('suspended_instant_sales', function (Blueprint $table) {
            $table->dropIndex('suspended_sales_type_status_user_index');
            $table->dropColumn('save_type');
        });
    }
};
