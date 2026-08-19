<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('app_development_task_message_reactions')) {
            return;
        }

        Schema::create('app_development_task_message_reactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_development_task_message_id');
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users', indexName: 'adt_msg_react_user_fk')
                ->nullOnDelete();
            $table->string('reaction', 16);
            $table->timestamps();

            $table->unique(['app_development_task_message_id', 'user_id'], 'adt_msg_react_user_unique');
            $table->index(['app_development_task_message_id', 'reaction'], 'adt_msg_react_lookup');
        });

        if (Schema::hasTable('app_development_task_messages')) {
            Schema::table('app_development_task_message_reactions', function (Blueprint $table) {
                $table->foreign('app_development_task_message_id', 'adt_msg_react_msg_fk')
                    ->references('id')
                    ->on('app_development_task_messages')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('app_development_task_message_reactions');
    }
};
