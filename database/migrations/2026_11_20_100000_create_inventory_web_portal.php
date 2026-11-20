<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('inventory_web_role', 16)->nullable()->index();
        });
        Schema::table('products', fn (Blueprint $table) => $table->string('inventory_web_image', 512)->nullable());
        Schema::create('inventory_count_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('status', 16)->default('open');
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });
        Schema::create('inventory_location_balances', function (Blueprint $table) {
            $table->id();
            $table->string('identity_key', 100)->unique();
            $table->unsignedBigInteger('product_id')->index();
            $table->unsignedBigInteger('size_color_id')->nullable()->index();
            $table->integer('showroom')->default(0);
            $table->integer('warehouse')->default(0);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('inventory_web_counts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('product_id');
            $table->string('place', 16);
            $table->json('lines');
            $table->json('stock_snapshot');
            $table->string('status', 16)->default('draft');
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamp('counted_at')->nullable();
            $table->unsignedBigInteger('counted_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamps();
            $table->unique(['session_id', 'product_id', 'place'], 'inventory_count_scope_unique');
            $table->index(['product_id', 'place', 'counted_at']);
        });
        Schema::create('inventory_web_documents', function (Blueprint $table) {
            $table->id();
            $table->string('request_key', 80)->unique();
            $table->string('request_hash', 64);
            $table->string('type', 24);
            $table->unsignedBigInteger('session_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->string('from_place', 16)->nullable();
            $table->string('to_place', 16)->nullable();
            $table->date('document_date');
            $table->json('lines');
            $table->text('reason');
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });
        Schema::create('inventory_web_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->unsignedBigInteger('user_id');
            $table->string('action', 40);
            $table->json('details');
            $table->timestamps();
        });
        Schema::create('inventory_web_variant_states', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('size_color_id')->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['inventory_web_variant_states', 'inventory_web_events', 'inventory_web_documents', 'inventory_web_counts', 'inventory_location_balances', 'inventory_count_sessions'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('inventory_web_role'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('inventory_web_image'));
    }
};
