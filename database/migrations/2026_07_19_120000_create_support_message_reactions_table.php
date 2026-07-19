<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_message_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_message_id')->constrained('support_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('employee_detail_id')->nullable()->constrained('employee_details')->nullOnDelete();
            $table->string('reaction', 16);
            $table->timestamps();

            $table->unique(['support_message_id', 'user_id'], 'support_msg_reactions_user_unique');
            $table->index(['support_message_id', 'reaction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_message_reactions');
    }
};
