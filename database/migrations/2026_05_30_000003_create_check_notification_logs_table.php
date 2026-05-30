<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('check_notification_logs')) {
            return;
        }

        Schema::create('check_notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->constrained('check_notification_rules')->cascadeOnDelete();
            $table->string('check_type', 20);
            $table->unsignedBigInteger('check_id');
            $table->string('event_type', 40);
            $table->string('phone')->nullable();
            $table->text('message')->nullable();
            $table->string('status', 30)->default('pending');
            $table->text('response')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['rule_id', 'check_type', 'check_id', 'event_type'], 'check_notification_unique');
            $table->index(['event_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_notification_logs');
    }
};
