<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->timestamp('customer_deleted_at')->nullable()->after('is_automatic');
        });

        Schema::create('whatsapp_message_user_hides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_message_id')
                ->constrained('whatsapp_messages')
                ->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['whatsapp_message_id', 'user_id'], 'wa_message_user_hide_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_message_user_hides');
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropColumn('customer_deleted_at');
        });
    }
};
