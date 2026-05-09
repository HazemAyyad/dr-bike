<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('attendace_qrs')) {
            return;
        }

        Schema::table('attendace_qrs', function (Blueprint $table) {
            if (Schema::hasColumn('attendace_qrs', 'file_name')) {
                return;
            }
            $table->string('file_name')->nullable()->after('code_text');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('attendace_qrs')) {
            return;
        }

        Schema::table('attendace_qrs', function (Blueprint $table) {
            if (! Schema::hasColumn('attendace_qrs', 'file_name')) {
                return;
            }
            $table->dropColumn('file_name');
        });
    }
};

