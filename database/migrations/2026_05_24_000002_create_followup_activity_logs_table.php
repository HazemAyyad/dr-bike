<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('followup_activity_logs')) {
            return;
        }

        Schema::create('followup_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('followup_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->string('description')->nullable();
            $table->json('changes')->nullable();
            $table->timestamps();

            $table->foreign('followup_id')->references('id')->on('followups')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('followup_activity_logs');
    }
};
