<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outgoing_checks', function (Blueprint $table) {
            if (! Schema::hasColumn('outgoing_checks', 'back_image')) {
                $table->string('back_image')->nullable()->after('img');
            }
        });
    }

    public function down(): void
    {
        Schema::table('outgoing_checks', function (Blueprint $table) {
            if (Schema::hasColumn('outgoing_checks', 'back_image')) {
                $table->dropColumn('back_image');
            }
        });
    }
};
