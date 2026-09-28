<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outgoing_checks', function (Blueprint $table) {
            $table->foreignId('parent_outgoing_check_id')->nullable()->after('id')
                ->constrained('outgoing_checks')->nullOnDelete();
            $table->foreignId('origin_installment_id')->nullable()->after('parent_outgoing_check_id')
                ->constrained('outgoing_check_installments')->nullOnDelete();
            $table->index(['parent_outgoing_check_id', 'status']);
        });

        Schema::table('outgoing_check_installments', function (Blueprint $table) {
            $table->foreignId('replacement_outgoing_check_id')->nullable()->after('outgoing_check_id')
                ->constrained('outgoing_checks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('outgoing_check_installments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replacement_outgoing_check_id');
        });
        Schema::table('outgoing_checks', function (Blueprint $table) {
            $table->dropIndex(['parent_outgoing_check_id', 'status']);
            $table->dropConstrainedForeignId('origin_installment_id');
            $table->dropConstrainedForeignId('parent_outgoing_check_id');
        });
    }
};
