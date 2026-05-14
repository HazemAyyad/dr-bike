<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('type', 64)->index();
            $table->string('title');
            $table->text('body');
            $table->foreignId('employee_id')->nullable()->constrained('employee_details')->nullOnDelete();
            $table->string('related_type', 64)->nullable()->index();
            $table->unsignedBigInteger('related_id')->nullable()->index();
            $table->json('data')->nullable();
            $table->boolean('is_read')->default(false)->index();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_notifications');
    }
};
