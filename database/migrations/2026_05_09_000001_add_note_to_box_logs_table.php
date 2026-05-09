<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('box_logs')) {
            return;
        }

        Schema::table('box_logs', function (Blueprint $table) {
            if (Schema::hasColumn('box_logs', 'note')) {
                return;
            }
            $table->text('note')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('box_logs')) {
            return;
        }

        Schema::table('box_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('box_logs', 'note')) {
                return;
            }
            $table->dropColumn('note');
        });
    }
};

