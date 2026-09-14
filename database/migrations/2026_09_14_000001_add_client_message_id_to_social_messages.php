<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->string('client_message_id', 100)->nullable()->index()->after('meta_message_id');
        });
        Schema::table('social_messages', function (Blueprint $table) {
            $table->string('client_message_id', 100)->nullable()->index()->after('meta_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropColumn('client_message_id');
        });
        Schema::table('social_messages', function (Blueprint $table) {
            $table->dropColumn('client_message_id');
        });
    }
};
