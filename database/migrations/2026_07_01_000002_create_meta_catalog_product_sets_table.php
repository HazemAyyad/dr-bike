<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('meta_catalog_product_sets')) {
            return;
        }

        Schema::create('meta_catalog_product_sets', function (Blueprint $table) {
            $table->id();
            $table->enum('source_type', ['category', 'sub_category']);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('parent_source_id')->nullable();
            $table->string('name');
            $table->string('meta_product_set_id')->nullable()->index();
            $table->string('filter_field');
            $table->string('filter_value');
            $table->json('filter_payload')->nullable();
            $table->enum('sync_status', ['pending', 'synced', 'failed', 'disabled'])->default('pending')->index();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'source_id']);
            $table->index(['parent_source_id', 'source_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_catalog_product_sets');
    }
};
