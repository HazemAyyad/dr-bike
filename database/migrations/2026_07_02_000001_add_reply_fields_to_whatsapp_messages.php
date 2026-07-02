<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->foreignId('reply_to_message_id')
                ->nullable()
                ->after('sent_by')
                ->constrained('whatsapp_messages')
                ->nullOnDelete();
            $table->string('reply_to_meta_message_id')
                ->nullable()
                ->after('reply_to_message_id')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropForeign(['reply_to_message_id']);
            $table->dropColumn(['reply_to_message_id', 'reply_to_meta_message_id']);
        });
    }
};
