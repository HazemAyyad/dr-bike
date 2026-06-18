<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_order_shiply_events')) {
            return;
        }

        Schema::create('sales_order_shiply_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
            $table->string('parcel_code', 100);
            $table->unsignedTinyInteger('parcel_status_id');
            $table->unsignedTinyInteger('parcel_position_id')->nullable();
            $table->string('note', 500)->nullable();
            $table->string('shiply_mode', 10)->nullable();
            $table->string('source', 20)->default('webhook');
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['sales_order_id', 'occurred_at']);
            $table->index('parcel_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_shiply_events');
    }
};
