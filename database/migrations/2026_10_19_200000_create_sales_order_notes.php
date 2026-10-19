<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_order_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
            $table->string('scope', 20)->default('external');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['sales_order_id', 'scope']);
        });
        Schema::table('sales_order_media', function (Blueprint $table) {
            $table->foreignId('sales_order_note_id')->nullable()->constrained('sales_order_notes')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_order_media', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_order_note_id');
        });
        Schema::dropIfExists('sales_order_notes');
    }
};
