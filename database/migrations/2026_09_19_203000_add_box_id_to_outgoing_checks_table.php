<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('outgoing_checks', 'box_id')) {
            Schema::table('outgoing_checks', function (Blueprint $table) {
                $table->unsignedBigInteger('box_id')->nullable()->after('seller_id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('outgoing_checks', 'box_id')) {
            Schema::table('outgoing_checks', function (Blueprint $table) {
                $table->dropColumn('box_id');
            });
        }
    }
};
