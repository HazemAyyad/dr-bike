<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('person_product_price_tiers')) {
            $this->ensureShortIndex();
            return;
        }

        Schema::create('person_product_price_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_product_setting_id')
                ->constrained('person_product_settings')
                ->cascadeOnDelete();
            $table->unsignedInteger('min_qty');
            $table->unsignedInteger('max_qty')->nullable();
            $table->decimal('unit_price', 12, 2);
            $table->timestamps();

            $table->index(['person_product_setting_id', 'min_qty'], 'pppt_setting_min_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_product_price_tiers');
    }

    private function ensureShortIndex(): void
    {
        $database = DB::getDatabaseName();
        $exists = DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', 'person_product_price_tiers')
            ->where('index_name', 'pppt_setting_min_idx')
            ->exists();

        if ($exists) {
            return;
        }

        Schema::table('person_product_price_tiers', function (Blueprint $table) {
            $table->index(['person_product_setting_id', 'min_qty'], 'pppt_setting_min_idx');
        });
    }
};
