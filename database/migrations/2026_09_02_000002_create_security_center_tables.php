<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_ip_blocks', function (Blueprint $table) {
            $table->id();
            $table->string('ip_address', 45)->unique();
            $table->string('reason', 500)->nullable();
            $table->boolean('active')->default(true)->index();
            $table->timestamp('blocked_at');
            $table->timestamp('expires_at')->nullable()->index();
            $table->string('created_by_ip', 45)->nullable();
            $table->timestamps();
        });

        Schema::create('security_access_visitors', function (Blueprint $table) {
            $table->id();
            $table->char('visitor_key', 64)->unique();
            $table->string('ip_address', 45)->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('user_name')->nullable();
            $table->string('user_type', 50)->nullable();
            $table->string('device_type', 50)->nullable();
            $table->string('country', 120)->nullable();
            $table->string('country_code', 8)->nullable();
            $table->string('region', 150)->nullable();
            $table->string('city', 150)->nullable();
            $table->string('isp', 255)->nullable();
            $table->timestamp('geo_updated_at')->nullable()->index();
            $table->string('geo_error', 255)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('last_method', 10)->nullable();
            $table->string('last_route', 255)->nullable();
            $table->unsignedSmallInteger('last_status')->nullable()->index();
            $table->unsignedBigInteger('observations')->default(1);
            $table->timestamp('first_seen_at')->index();
            $table->timestamp('last_seen_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_access_visitors');
        Schema::dropIfExists('security_ip_blocks');
    }
};
