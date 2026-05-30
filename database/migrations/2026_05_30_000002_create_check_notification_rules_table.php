<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('check_notification_rules')) {
            return;
        }

        Schema::create('check_notification_rules', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);
            $table->unsignedInteger('days')->default(0);
            $table->string('trigger_mode', 20)->default('at_time');
            $table->time('send_time')->nullable();
            $table->text('message');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_notification_rules');
    }
};
