<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('purchase_invoice_purge_backups')) {
            return;
        }

        Schema::create('purchase_invoice_purge_backups', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->unsignedBigInteger('bill_id')->index();
            $table->string('bill_reference', 80);
            $table->string('workflow_status', 40)->nullable();
            $table->text('reason');
            $table->longText('payload');
            $table->json('result_summary')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['created_by', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_invoice_purge_backups');
    }
};
