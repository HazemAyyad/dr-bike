<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchase_issue_resolutions')) {
            Schema::create('purchase_issue_resolutions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('bill_id')->constrained('bills')->cascadeOnDelete();
                $table->foreignId('bill_item_id')->constrained('bill_items')->cascadeOnDelete();
                $table->foreignId('purchase_receipt_item_id')->nullable()->constrained('purchase_receipt_items')->nullOnDelete();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->string('issue_type', 40);
                $table->string('resolution', 60);
                $table->decimal('quantity', 14, 4);
                $table->decimal('negotiated_unit_price', 14, 4)->nullable();
                $table->decimal('financial_adjustment', 14, 4)->default(0);
                $table->text('reason')->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['bill_id', 'issue_type']);
                $table->index(['bill_item_id', 'resolution']);
            });
        }
    }

    public function down(): void
    {
        // Intentionally preserve audit history on rollback.
    }
};
