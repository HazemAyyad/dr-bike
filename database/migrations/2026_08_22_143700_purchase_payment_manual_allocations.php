<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchase_payment_allocations')) {
            Schema::create('purchase_payment_allocations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('purchase_payment_id')->constrained('purchase_payments')->cascadeOnDelete();
                $table->foreignId('bill_id')->constrained('bills')->cascadeOnDelete();
                $table->decimal('amount', 14, 4);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['purchase_payment_id', 'bill_id'], 'purchase_payment_bill_unique');
            });
        }
    }

    public function down(): void
    {
        // Additive production migration. Data is intentionally preserved.
    }
};
