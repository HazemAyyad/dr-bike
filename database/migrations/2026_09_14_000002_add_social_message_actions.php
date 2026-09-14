<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['whatsapp_messages', 'social_messages'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->timestamp('pinned_at')->nullable()->index();
                $table->foreignId('pinned_by')->nullable()->constrained('users')->nullOnDelete();
            });
        }

        Schema::create('social_message_user_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('channel', ['whatsapp', 'facebook', 'instagram']);
            $table->unsignedBigInteger('message_id');
            $table->boolean('starred')->default(false);
            $table->string('reaction', 16)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'channel', 'message_id'], 'social_message_user_state_unique');
        });

        Schema::create('social_message_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reported_by')->constrained('users')->cascadeOnDelete();
            $table->enum('channel', ['whatsapp', 'facebook', 'instagram']);
            $table->unsignedBigInteger('message_id');
            $table->string('reason', 500)->nullable();
            $table->timestamps();
            $table->unique(['reported_by', 'channel', 'message_id'], 'social_message_report_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_message_reports');
        Schema::dropIfExists('social_message_user_states');
        foreach (['whatsapp_messages', 'social_messages'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('pinned_by');
                $table->dropColumn('pinned_at');
            });
        }
    }
};
