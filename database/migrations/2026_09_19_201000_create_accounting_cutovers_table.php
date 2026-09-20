<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('accounting_cutovers')) {
            return;
        }
        Schema::create('accounting_cutovers', function (Blueprint $table) {
            $table->id();
            $table->date('cutover_date')->index();
            $table->string('status', 20)->default('applied')->index();
            $table->json('snapshot');
            $table->json('journal_entry_ids')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_cutovers');
    }
};
