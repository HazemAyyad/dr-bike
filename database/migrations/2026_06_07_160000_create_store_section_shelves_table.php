<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('store_section_shelves')) {
            Schema::create('store_section_shelves', function (Blueprint $table) {
                $table->id();
                $table->foreignId('store_section_id')
                    ->constrained('store_sections')
                    ->cascadeOnDelete();
                $table->string('shelf_number', 30);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
                $table->unique(['store_section_id', 'shelf_number']);
            });
        }

        if (Schema::hasTable('store_section_shelves') && Schema::hasColumn('products', 'shelf_number')) {
            $rows = DB::table('products')
                ->select('store_section_id', 'shelf_number')
                ->whereNotNull('store_section_id')
                ->whereNotNull('shelf_number')
                ->where('shelf_number', '!=', '')
                ->distinct()
                ->get();

            foreach ($rows as $row) {
                $exists = DB::table('store_section_shelves')
                    ->where('store_section_id', $row->store_section_id)
                    ->where('shelf_number', $row->shelf_number)
                    ->exists();
                if (! $exists) {
                    DB::table('store_section_shelves')->insert([
                        'store_section_id' => $row->store_section_id,
                        'shelf_number' => $row->shelf_number,
                        'sort_order' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('store_section_shelves');
    }
};
