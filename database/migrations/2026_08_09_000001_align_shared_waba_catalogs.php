<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $sharedWabaId = '1021382140304311';
        $sharedCatalogId = '2145157066409174';
        $accountIds = [];

        if (Schema::hasTable('whatsapp_accounts')) {
            $accountIds = DB::table('whatsapp_accounts')
                ->where('waba_id', $sharedWabaId)
                ->pluck('id')
                ->all();

            DB::table('whatsapp_accounts')
                ->where('waba_id', $sharedWabaId)
                ->update([
                    'catalog_id' => $sharedCatalogId,
                    'updated_at' => now(),
                ]);
        }

        foreach (['meta_catalog_product_syncs', 'meta_catalog_sync_logs', 'meta_catalog_product_sets'] as $table) {
            if (! Schema::hasTable($table) || empty($accountIds)) {
                continue;
            }

            DB::table($table)
                ->whereIn('whatsapp_account_id', $accountIds)
                ->update([
                    'catalog_id' => $sharedCatalogId,
                    'updated_at' => now(),
                ]);
        }

        if (Schema::hasTable('meta_catalog_product_sets')) {
            Schema::table('meta_catalog_product_sets', function (Blueprint $table) {
                try {
                    $table->dropUnique('meta_catalog_product_sets_source_type_source_id_unique');
                } catch (\Throwable) {
                    // Older databases may already have the catalog-aware index.
                }
            });

            Schema::table('meta_catalog_product_sets', function (Blueprint $table) {
                try {
                    $table->unique(['catalog_id', 'source_type', 'source_id'], 'meta_catalog_sets_catalog_source_unique');
                } catch (\Throwable) {
                    // Keep migration idempotent across local databases.
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('meta_catalog_product_sets')) {
            Schema::table('meta_catalog_product_sets', function (Blueprint $table) {
                try {
                    $table->dropUnique('meta_catalog_sets_catalog_source_unique');
                } catch (\Throwable) {
                    //
                }
            });

            Schema::table('meta_catalog_product_sets', function (Blueprint $table) {
                try {
                    $table->unique(['source_type', 'source_id']);
                } catch (\Throwable) {
                    //
                }
            });
        }
    }
};
