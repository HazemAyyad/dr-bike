<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('returns', 'source_type')) {
            Schema::table('returns', function (Blueprint $table) {
                $table->string('source_type', 20)->default('invoice')->after('bill_id');
            });
        }

        DB::table('returns')->whereNull('bill_id')->update(['source_type' => 'direct']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('returns', 'source_type')) {
            Schema::table('returns', fn (Blueprint $table) => $table->dropColumn('source_type'));
        }
    }
};
