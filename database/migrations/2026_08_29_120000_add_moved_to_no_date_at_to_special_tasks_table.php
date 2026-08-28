<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('special_tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('special_tasks', 'moved_to_no_date_at')) {
                $table->timestamp('moved_to_no_date_at')->nullable()->after('end_date')->index();
            }
        });

        // Preserve tasks that were already visible in the legacy no-date list.
        DB::table('special_tasks')
            ->whereNull('moved_to_no_date_at')
            ->where('end_date', '<', now())
            ->where('status', '!=', 'completed')
            ->where('is_canceled', 0)
            ->update(['moved_to_no_date_at' => DB::raw('end_date')]);
    }

    public function down(): void
    {
        Schema::table('special_tasks', function (Blueprint $table) {
            if (Schema::hasColumn('special_tasks', 'moved_to_no_date_at')) {
                $table->dropColumn('moved_to_no_date_at');
            }
        });
    }
};
