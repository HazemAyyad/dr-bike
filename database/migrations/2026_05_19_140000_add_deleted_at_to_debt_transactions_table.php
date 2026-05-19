<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('debt_transactions')) {
            return;
        }

        if (! Schema::hasColumn('debt_transactions', 'deleted_at')) {
            Schema::table('debt_transactions', function (Blueprint $table) {
                $table->timestamp('deleted_at')->nullable()->after('archived_at');
                $table->index(['customer_id', 'deleted_at']);
                $table->index(['seller_id', 'deleted_at']);
            });
        }

        // Move instant-sale cancellations from archive to deleted (non-restorable).
        if (Schema::hasColumn('debt_transactions', 'deleted_at')) {
            DB::table('debt_transactions')
                ->where('source', 'instant_sale')
                ->whereNotNull('archived_at')
                ->whereNull('deleted_at')
                ->update([
                    'deleted_at' => DB::raw('archived_at'),
                    'archived_at' => null,
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('debt_transactions')) {
            return;
        }

        if (Schema::hasColumn('debt_transactions', 'deleted_at')) {
            DB::table('debt_transactions')
                ->where('source', 'instant_sale')
                ->whereNotNull('deleted_at')
                ->whereNull('archived_at')
                ->update([
                    'archived_at' => DB::raw('deleted_at'),
                    'deleted_at' => null,
                ]);

            Schema::table('debt_transactions', function (Blueprint $table) {
                $table->dropIndex(['customer_id', 'deleted_at']);
                $table->dropIndex(['seller_id', 'deleted_at']);
                $table->dropColumn('deleted_at');
            });
        }
    }
};
