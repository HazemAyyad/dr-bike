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
            if (! Schema::hasColumn('box_logs', 'value')) {
                $table->double('value')->nullable()->after('description');
            }
            if (! Schema::hasColumn('box_logs', 'type')) {
                $table->string('type', 32)->nullable()->after('value');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('box_logs')) {
            return;
        }

        Schema::table('box_logs', function (Blueprint $table) {
            if (Schema::hasColumn('box_logs', 'type')) {
                $table->dropColumn('type');
            }
            if (Schema::hasColumn('box_logs', 'value')) {
                $table->dropColumn('value');
            }
        });
    }
};
