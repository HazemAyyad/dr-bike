<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('whatsapp_accounts')) {
            Schema::create('whatsapp_accounts', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('display_phone_number', 32)->nullable()->index();
                $table->string('phone_number_id')->unique();
                $table->string('waba_id')->nullable()->index();
                $table->string('catalog_id')->nullable()->index();
                $table->string('access_token_env_key')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->boolean('is_verified')->default(false)->index();
                $table->unsignedInteger('sort_order')->default(0);
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('whatsapp_account_catalog_rules')) {
            Schema::create('whatsapp_account_catalog_rules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('whatsapp_account_id')->constrained('whatsapp_accounts')->cascadeOnDelete();
                $table->enum('source_type', ['all', 'category', 'sub_category'])->default('all');
                $table->unsignedBigInteger('source_id')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();

                $table->unique(['whatsapp_account_id', 'source_type', 'source_id'], 'wa_catalog_rules_unique');
            });
        }

        if (! Schema::hasTable('meta_catalog_product_syncs')) {
            Schema::create('meta_catalog_product_syncs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('whatsapp_account_id')->nullable()->constrained('whatsapp_accounts')->nullOnDelete();
                $table->string('catalog_id')->index();
                $table->unsignedBigInteger('product_id')->index();
                $table->unsignedBigInteger('variant_id')->nullable()->index();
                $table->string('meta_catalog_item_id')->nullable()->index();
                $table->string('meta_catalog_retailer_id')->index();
                $table->enum('sync_status', ['pending', 'synced', 'failed', 'disabled'])->nullable()->index();
                $table->timestamp('last_synced_at')->nullable();
                $table->text('last_error')->nullable();
                $table->json('payload')->nullable();
                $table->timestamps();

                $table->unique(['catalog_id', 'product_id', 'variant_id'], 'meta_catalog_syncs_target_unique');
                $table->unique(['catalog_id', 'meta_catalog_retailer_id'], 'meta_catalog_syncs_retailer_unique');
            });
        }

        $this->addColumnIfMissing('whatsapp_conversations', 'whatsapp_account_id', function (Blueprint $table) {
            $table->foreignId('whatsapp_account_id')->nullable()->after('id')->constrained('whatsapp_accounts')->nullOnDelete();
        });
        $this->addColumnIfMissing('whatsapp_messages', 'whatsapp_account_id', function (Blueprint $table) {
            $table->foreignId('whatsapp_account_id')->nullable()->after('id')->constrained('whatsapp_accounts')->nullOnDelete();
        });
        $this->addColumnIfMissing('meta_catalog_sync_logs', 'whatsapp_account_id', function (Blueprint $table) {
            $table->foreignId('whatsapp_account_id')->nullable()->after('id')->constrained('whatsapp_accounts')->nullOnDelete();
        });
        $this->addColumnIfMissing('meta_catalog_sync_logs', 'catalog_id', function (Blueprint $table) {
            $table->string('catalog_id')->nullable()->after('whatsapp_account_id')->index();
        });
        $this->addColumnIfMissing('meta_catalog_product_sets', 'whatsapp_account_id', function (Blueprint $table) {
            $table->foreignId('whatsapp_account_id')->nullable()->after('id')->constrained('whatsapp_accounts')->nullOnDelete();
        });
        $this->addColumnIfMissing('meta_catalog_product_sets', 'catalog_id', function (Blueprint $table) {
            $table->string('catalog_id')->nullable()->after('whatsapp_account_id')->index();
        });

        DB::table('whatsapp_accounts')->updateOrInsert(
            ['phone_number_id' => '1225704637288803'],
            [
                'name' => 'Dr Bike Palestine',
                'display_phone_number' => '970594672857',
                'waba_id' => '1021382140304311',
                'catalog_id' => env('META_CATALOG_ID', '1014695750934512'),
                'access_token_env_key' => 'WHATSAPP_ACCESS_TOKEN',
                'is_active' => true,
                'is_verified' => true,
                'sort_order' => 10,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        DB::table('whatsapp_accounts')->updateOrInsert(
            ['phone_number_id' => '1318574464666434'],
            [
                'name' => 'Doctor Bike Israel',
                'display_phone_number' => '972569600809',
                'waba_id' => '1021382140304311',
                'catalog_id' => '2145157066409174',
                'access_token_env_key' => 'WHATSAPP_ACCESS_TOKEN',
                'is_active' => false,
                'is_verified' => false,
                'sort_order' => 20,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $defaultAccountId = DB::table('whatsapp_accounts')
            ->where('phone_number_id', '1225704637288803')
            ->value('id');
        if ($defaultAccountId) {
            DB::table('whatsapp_conversations')
                ->whereNull('whatsapp_account_id')
                ->update(['whatsapp_account_id' => $defaultAccountId]);
            DB::table('whatsapp_messages')
                ->whereNull('whatsapp_account_id')
                ->update(['whatsapp_account_id' => $defaultAccountId]);
        }
    }

    public function down(): void
    {
        foreach ([
            'meta_catalog_product_sets' => ['catalog_id', 'whatsapp_account_id'],
            'meta_catalog_sync_logs' => ['catalog_id', 'whatsapp_account_id'],
            'whatsapp_messages' => ['whatsapp_account_id'],
            'whatsapp_conversations' => ['whatsapp_account_id'],
        ] as $tableName => $columns) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($tableName, $columns) {
                foreach ($columns as $column) {
                    if (Schema::hasColumn($tableName, $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::dropIfExists('meta_catalog_product_syncs');
        Schema::dropIfExists('whatsapp_account_catalog_rules');
        Schema::dropIfExists('whatsapp_accounts');
    }

    private function addColumnIfMissing(string $tableName, string $column, callable $callback): void
    {
        if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, $column)) {
            return;
        }

        Schema::table($tableName, $callback);
    }
};
