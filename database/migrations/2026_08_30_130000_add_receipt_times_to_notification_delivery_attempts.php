<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_delivery_attempts', function (Blueprint $table) {
            $table->timestamp('delivered_at')->nullable()->after('sent_at');
            $table->timestamp('opened_at')->nullable()->after('delivered_at');
        });
    }

    public function down(): void
    {
        Schema::table('notification_delivery_attempts', function (Blueprint $table) {
            $table->dropColumn(['delivered_at', 'opened_at']);
        });
    }
};
