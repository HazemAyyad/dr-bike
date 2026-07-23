<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_development_task_message_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_development_task_message_id')
                ->constrained('app_development_task_messages', indexName: 'adt_msg_react_msg_fk')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users', indexName: 'adt_msg_react_user_fk')
                ->nullOnDelete();
            $table->string('reaction', 16);
            $table->timestamps();

            $table->unique(['app_development_task_message_id', 'user_id'], 'adt_msg_react_user_unique');
            $table->index(['app_development_task_message_id', 'reaction'], 'adt_msg_react_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_development_task_message_reactions');
    }
};
