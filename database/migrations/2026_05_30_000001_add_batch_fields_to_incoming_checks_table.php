<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incoming_checks', function (Blueprint $table) {
            if (! Schema::hasColumn('incoming_checks', 'received_at')) {
                $table->date('received_at')->nullable()->after('due_date');
            }

            if (! Schema::hasColumn('incoming_checks', 'batch_number')) {
                $col = $table->string('batch_number', 60)->nullable();
                if (Schema::hasColumn('incoming_checks', 'notes')) {
                    $col->after('notes');
                }
                $col->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('incoming_checks', function (Blueprint $table) {
            if (Schema::hasColumn('incoming_checks', 'batch_number')) {
                $table->dropIndex(['batch_number']);
                $table->dropColumn('batch_number');
            }

            if (Schema::hasColumn('incoming_checks', 'received_at')) {
                $table->dropColumn('received_at');
            }
        });
    }
};
