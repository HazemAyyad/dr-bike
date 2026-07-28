<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('maintenance_daily_sessions')) {
            return;
        }

        Schema::table('maintenance_daily_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('maintenance_daily_sessions', 'closing_requested_at')) {
                $table->timestamp('closing_requested_at')->nullable()->after('opened_by_user_id');
            }
            if (! Schema::hasColumn('maintenance_daily_sessions', 'closing_requested_by_user_id')) {
                $table->unsignedBigInteger('closing_requested_by_user_id')->nullable()->after('closing_requested_at');
                $table->foreign('closing_requested_by_user_id')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('maintenance_daily_sessions', 'closing_request_note')) {
                $table->text('closing_request_note')->nullable()->after('closing_requested_by_user_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('maintenance_daily_sessions')) {
            return;
        }

        Schema::table('maintenance_daily_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('maintenance_daily_sessions', 'closing_requested_by_user_id')) {
                $table->dropForeign(['closing_requested_by_user_id']);
                $table->dropColumn('closing_requested_by_user_id');
            }
            foreach (['closing_request_note', 'closing_requested_at'] as $column) {
                if (Schema::hasColumn('maintenance_daily_sessions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
