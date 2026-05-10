<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_reward_rules', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_reward_rules', 'status_label')) {
                $table->string('status_label')->nullable()->after('reward_amount');
            }
            if (! Schema::hasColumn('employee_reward_rules', 'status_color')) {
                $table->string('status_color', 16)->nullable()->after('status_label');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employee_reward_rules', function (Blueprint $table) {
            if (Schema::hasColumn('employee_reward_rules', 'status_color')) {
                $table->dropColumn('status_color');
            }
            if (Schema::hasColumn('employee_reward_rules', 'status_label')) {
                $table->dropColumn('status_label');
            }
        });
    }
};
