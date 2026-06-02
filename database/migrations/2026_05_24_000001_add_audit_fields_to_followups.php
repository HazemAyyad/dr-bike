<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('followups', function (Blueprint $table) {
            if (! Schema::hasColumn('followups', 'created_by_user_id')) {
                $col = $table->unsignedBigInteger('created_by_user_id')->nullable();
                if (Schema::hasColumn('followups', 'seller_id')) {
                    $col->after('seller_id');
                }
                $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
            }

            if (! Schema::hasColumn('followups', 'admin_only')) {
                $col = $table->boolean('admin_only')->default(false);
                if (Schema::hasColumn('followups', 'created_by_user_id')) {
                    $col->after('created_by_user_id');
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('followups', function (Blueprint $table) {
            if (Schema::hasColumn('followups', 'created_by_user_id')) {
                $table->dropForeign(['created_by_user_id']);
                $table->dropColumn('created_by_user_id');
            }

            if (Schema::hasColumn('followups', 'admin_only')) {
                $table->dropColumn('admin_only');
            }
        });
    }
};
