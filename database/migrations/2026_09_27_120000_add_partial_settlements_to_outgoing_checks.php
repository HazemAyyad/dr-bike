<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outgoing_checks', function (Blueprint $table) {
            $table->string('settlement_status')->default('unpaid')->after('status')->index();
            $table->timestamp('restructured_at')->nullable()->after('settlement_status');
        });

        Schema::create('outgoing_check_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outgoing_check_id')->constrained('outgoing_checks')->cascadeOnDelete();
            $table->foreignId('box_id')->constrained('boxes')->restrictOnDelete();
            $table->decimal('amount', 14, 4);
            $table->date('paid_at');
            $table->string('idempotency_key')->unique();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('outgoing_check_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outgoing_check_id')->constrained('outgoing_checks')->cascadeOnDelete();
            $table->decimal('amount', 14, 4);
            $table->date('due_date');
            $table->string('instrument_type')->default('same_check');
            $table->string('check_id')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('status')->default('pending')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outgoing_check_installments');
        Schema::dropIfExists('outgoing_check_settlements');
        Schema::table('outgoing_checks', function (Blueprint $table) {
            $table->dropColumn(['settlement_status', 'restructured_at']);
        });
    }
};
