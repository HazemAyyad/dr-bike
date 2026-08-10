<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_details', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_details', 'network_connected')) {
                $table->boolean('network_connected')
                    ->default(false)
                    ->after('wifi_connected');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employee_details', function (Blueprint $table) {
            if (Schema::hasColumn('employee_details', 'network_connected')) {
                $table->dropColumn('network_connected');
            }
        });
    }
};
