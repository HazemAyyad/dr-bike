<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_tags')) {
            Schema::create('product_tags', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->string('color', 32);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('product_product_tag')) {
            Schema::create('product_product_tag', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('product_tag_id');
                $table->timestamps();
                $table->unique(['product_id', 'product_tag_id']);
                $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
                $table->foreign('product_tag_id')->references('id')->on('product_tags')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('product_code_sequences')) {
            Schema::create('product_code_sequences', function (Blueprint $table) {
                $table->unsignedTinyInteger('id')->primary();
                $table->unsignedInteger('next_number')->default(1);
            });
        }

        if (! Schema::hasColumn('products', 'product_code')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('product_code', 6)->nullable()->after('id');
            });
        }

        $maxExisting = DB::table('products')->whereNotNull('product_code')->max(DB::raw('CAST(product_code AS UNSIGNED)'));
        $n = $maxExisting ? ((int) $maxExisting) + 1 : 1;
        foreach (DB::table('products')->whereNull('product_code')->orderBy('id')->pluck('id') as $pid) {
            $code = str_pad((string) $n, 6, '0', STR_PAD_LEFT);
            DB::table('products')->where('id', $pid)->update(['product_code' => $code]);
            $n++;
        }
        $nextAfter = $n;

        try {
            Schema::table('products', function (Blueprint $table) {
                $table->unique('product_code');
            });
        } catch (\Throwable) {
        }

        if (! DB::table('product_code_sequences')->where('id', 1)->exists()) {
            DB::table('product_code_sequences')->insert([
                'id' => 1,
                'next_number' => $nextAfter,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'product_code')) {
                try {
                    $table->dropUnique(['product_code']);
                } catch (\Throwable) {
                }
                $table->dropColumn('product_code');
            }
        });

        Schema::dropIfExists('product_code_sequences');
        Schema::dropIfExists('product_product_tag');
        Schema::dropIfExists('product_tags');
    }
};
