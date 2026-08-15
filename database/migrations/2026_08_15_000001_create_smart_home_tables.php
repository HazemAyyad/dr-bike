<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('smart_home_tuya_users')) {
            Schema::create('smart_home_tuya_users', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('tuya_uid')->nullable()->unique();
                $table->string('region', 64)->nullable();
                $table->json('raw_metadata')->nullable();
                $table->timestamp('last_login_at')->nullable();
                $table->timestamps();

                $table->unique('user_id');
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('smart_homes')) {
            Schema::create('smart_homes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('tuya_home_id')->nullable()->index();
                $table->string('name');
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->string('geo_name')->nullable();
                $table->boolean('is_default')->default(false)->index();
                $table->string('status', 32)->default('active')->index();
                $table->json('raw_metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['user_id', 'status']);
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('smart_rooms')) {
            Schema::create('smart_rooms', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('smart_home_id');
                $table->string('tuya_room_id')->nullable()->index();
                $table->string('name');
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['smart_home_id', 'sort_order']);
                $table->foreign('smart_home_id')->references('id')->on('smart_homes')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('smart_devices')) {
            Schema::create('smart_devices', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('smart_home_id');
                $table->unsignedBigInteger('smart_room_id')->nullable();
                $table->string('tuya_device_id')->unique();
                $table->string('tuya_product_id')->nullable()->index();
                $table->string('tuya_uuid')->nullable()->index();
                $table->string('name');
                $table->string('category')->nullable()->index();
                $table->string('product_name')->nullable();
                $table->text('icon')->nullable();
                $table->string('protocol', 64)->nullable();
                $table->boolean('online')->default(false)->index();
                $table->string('model')->nullable();
                $table->string('manufacturer')->nullable();
                $table->json('raw_metadata')->nullable();
                $table->json('last_status')->nullable();
                $table->timestamp('paired_at')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['smart_home_id', 'online']);
                $table->foreign('smart_home_id')->references('id')->on('smart_homes')->cascadeOnDelete();
                $table->foreign('smart_room_id')->references('id')->on('smart_rooms')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('smart_device_activity_logs')) {
            Schema::create('smart_device_activity_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('smart_device_id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('action', 80)->index();
                $table->string('command_code')->nullable()->index();
                $table->json('command_value')->nullable();
                $table->boolean('success')->default(false)->index();
                $table->string('error_code')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();

                $table->index(['smart_device_id', 'created_at']);
                $table->foreign('smart_device_id')->references('id')->on('smart_devices')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_device_activity_logs');
        Schema::dropIfExists('smart_devices');
        Schema::dropIfExists('smart_rooms');
        Schema::dropIfExists('smart_homes');
        Schema::dropIfExists('smart_home_tuya_users');
    }
};
