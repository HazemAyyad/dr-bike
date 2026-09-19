<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_order_purge_backups')) {
            return;
        }

        Schema::create('sales_order_purge_backups', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->dateTime('cutoff_at');
            $table->unsignedBigInteger('max_order_id');
            $table->unsignedInteger('orders_count');
            $table->string('mode', 40)->default('with_effects');
            $table->string('status', 24)->default('available');
            $table->longText('payload');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_purge_backups');
    }
};
