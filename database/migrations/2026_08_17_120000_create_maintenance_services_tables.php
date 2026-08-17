<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('maintenance_services')) {
            Schema::create('maintenance_services', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->decimal('price', 10, 2)->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['is_active', 'name']);
            });
        }

        if (! Schema::hasTable('maintenance_service_media')) {
            Schema::create('maintenance_service_media', function (Blueprint $table) {
                $table->id();
                $table->foreignId('maintenance_service_id')
                    ->constrained('maintenance_services')
                    ->cascadeOnDelete();
                $table->string('file_name');
                $table->string('file_type', 20)->default('image');
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['maintenance_service_id', 'sort_order'], 'mnt_service_media_sort_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_service_media');
        Schema::dropIfExists('maintenance_services');
    }
};
