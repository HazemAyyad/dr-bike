<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('sub_phone')->nullable();
            $table->string('job_title')->nullable();
            $table->string('type')->nullable();
            $table->string('facebook_username')->nullable();
            $table->string('facebook_link')->nullable();
            $table->string('instagram_username')->nullable();
            $table->string('instagram_link')->nullable();
            $table->string('related_people')->nullable();
            $table->string('ID_image')->nullable();
            $table->string('license_image')->nullable();
            $table->string('work_address')->nullable();
            $table->string('relative_phone')->nullable();
            $table->string('relative_job_title')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_canceled')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sellers');
    }
};
