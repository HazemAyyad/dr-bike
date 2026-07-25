<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('password_reset_codes')) {
            return;
        }

        Schema::table('password_reset_codes', function (Blueprint $table) {
            if (! Schema::hasColumn('password_reset_codes', 'user_id')) {
                $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('password_reset_codes', 'delivery_method')) {
                $table->string('delivery_method', 20)->default('email')->after('token');
            }
            if (! Schema::hasColumn('password_reset_codes', 'expires_at')) {
                $table->timestamp('expires_at')->nullable()->after('delivery_method');
            }
            if (! Schema::hasColumn('password_reset_codes', 'used_at')) {
                $table->timestamp('used_at')->nullable()->after('expires_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('password_reset_codes')) {
            return;
        }

        Schema::table('password_reset_codes', function (Blueprint $table) {
            if (Schema::hasColumn('password_reset_codes', 'user_id')) {
                $table->dropConstrainedForeignId('user_id');
            }
            if (Schema::hasColumn('password_reset_codes', 'delivery_method')) {
                $table->dropColumn('delivery_method');
            }
            if (Schema::hasColumn('password_reset_codes', 'expires_at')) {
                $table->dropColumn('expires_at');
            }
            if (Schema::hasColumn('password_reset_codes', 'used_at')) {
                $table->dropColumn('used_at');
            }
        });
    }
};
