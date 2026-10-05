<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('outgoing_checks', 'notes')) {
            Schema::table('outgoing_checks', function (Blueprint $table) {
                $table->text('notes')->nullable()->after('box_id');
            });
        }

        if (! Schema::hasColumn('outgoing_checks', 'batch_number')) {
            Schema::table('outgoing_checks', function (Blueprint $table) {
                $table->string('batch_number')->nullable()->after('notes')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('outgoing_checks', 'batch_number')) {
            Schema::table('outgoing_checks', function (Blueprint $table) {
                $table->dropColumn('batch_number');
            });
        }
    }
};
