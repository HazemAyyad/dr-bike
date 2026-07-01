<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['products', 'size_colors'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'meta_catalog_item_id')) {
                    $table->string('meta_catalog_item_id')->nullable()->index();
                }
                if (! Schema::hasColumn($tableName, 'meta_catalog_retailer_id')) {
                    $table->string('meta_catalog_retailer_id')->nullable()->unique();
                }
                if (! Schema::hasColumn($tableName, 'meta_catalog_sync_status')) {
                    $table->enum('meta_catalog_sync_status', ['pending', 'synced', 'failed', 'disabled'])->nullable()->index();
                }
                if (! Schema::hasColumn($tableName, 'meta_catalog_last_synced_at')) {
                    $table->timestamp('meta_catalog_last_synced_at')->nullable();
                }
                if (! Schema::hasColumn($tableName, 'meta_catalog_last_error')) {
                    $table->text('meta_catalog_last_error')->nullable();
                }
                if (! Schema::hasColumn($tableName, 'meta_catalog_payload')) {
                    $table->json('meta_catalog_payload')->nullable();
                }
            });
        }

        if (! Schema::hasTable('meta_catalog_sync_logs')) {
            Schema::create('meta_catalog_sync_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('product_id')->nullable()->index();
                $table->unsignedBigInteger('variant_id')->nullable()->index();
                $table->enum('action', ['create', 'update', 'delete', 'disable', 'bulk_sync', 'test']);
                $table->enum('status', ['success', 'failed', 'queued'])->index();
                $table->string('meta_catalog_item_id')->nullable();
                $table->string('retailer_id')->nullable()->index();
                $table->json('request_payload')->nullable();
                $table->json('response_payload')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_catalog_sync_logs');

        foreach (['products', 'size_colors'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                foreach ([
                    'meta_catalog_item_id', 'meta_catalog_retailer_id', 'meta_catalog_sync_status',
                    'meta_catalog_last_synced_at', 'meta_catalog_last_error', 'meta_catalog_payload',
                ] as $column) {
                    if (Schema::hasColumn($tableName, $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
