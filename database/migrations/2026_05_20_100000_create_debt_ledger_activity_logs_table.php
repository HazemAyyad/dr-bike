<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('debt_ledger_activity_logs')) {
            return;
        }

        Schema::create('debt_ledger_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('debt_transaction_id')->nullable()->constrained('debt_transactions')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('seller_id')->nullable()->constrained('sellers')->nullOnDelete();
            $table->string('action', 64);
            $table->string('title');
            $table->text('description');
            $table->json('meta')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['debt_transaction_id', 'created_at']);
            $table->index(['customer_id', 'created_at']);
            $table->index(['seller_id', 'created_at']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_ledger_activity_logs');
    }
};
