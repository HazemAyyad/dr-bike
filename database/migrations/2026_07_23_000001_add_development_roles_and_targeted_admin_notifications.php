<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'development_role')) {
                $table->string('development_role', 32)->default('none')->index()->after('type');
            }
        });

        Schema::table('admin_notifications', function (Blueprint $table) {
            if (! Schema::hasColumn('admin_notifications', 'recipient_user_id')) {
                $table->foreignId('recipient_user_id')
                    ->nullable()
                    ->after('employee_id')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('admin_notifications', function (Blueprint $table) {
            if (Schema::hasColumn('admin_notifications', 'recipient_user_id')) {
                $table->dropConstrainedForeignId('recipient_user_id');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'development_role')) {
                $table->dropColumn('development_role');
            }
        });
    }
};
