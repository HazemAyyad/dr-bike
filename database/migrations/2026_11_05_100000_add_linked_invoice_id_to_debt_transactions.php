<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debt_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('linked_invoice_id')->nullable()->index();
            $table->uuid('receipt_request_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('debt_transactions', function (Blueprint $table) {
            $table->dropIndex(['linked_invoice_id']);
            $table->dropUnique(['receipt_request_id']);
            $table->dropColumn(['linked_invoice_id', 'receipt_request_id']);
        });
    }
};
