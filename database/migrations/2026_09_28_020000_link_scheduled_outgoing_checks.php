<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('outgoing_checks', 'parent_outgoing_check_id')) {
            Schema::table('outgoing_checks', function (Blueprint $table) {
                $table->foreignId('parent_outgoing_check_id')->nullable()->after('id')
                    ->constrained('outgoing_checks')->nullOnDelete();
                $table->index(['parent_outgoing_check_id', 'status']);
            });
        }

        if (! Schema::hasColumn('outgoing_checks', 'origin_installment_id')) {
            Schema::table('outgoing_checks', function (Blueprint $table) {
                $table->foreignId('origin_installment_id')->nullable()->after('parent_outgoing_check_id')
                    ->constrained('outgoing_check_installments')->nullOnDelete();
            });
        }

        $replacementColumnExists = Schema::hasColumn(
            'outgoing_check_installments',
            'replacement_outgoing_check_id'
        );

        if (! $replacementColumnExists) {
            Schema::table('outgoing_check_installments', function (Blueprint $table) {
                $table->foreignId('replacement_outgoing_check_id')->nullable()->after('outgoing_check_id');
            });
        }

        // This is intentionally separate so a MySQL migration interrupted after
        // adding the column can safely resume. Keep the name below 64 characters.
        Schema::table('outgoing_check_installments', function (Blueprint $table) {
            $table->foreign('replacement_outgoing_check_id', 'oc_installments_replacement_fk')
                ->references('id')->on('outgoing_checks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('outgoing_check_installments', function (Blueprint $table) {
            $table->dropForeign('oc_installments_replacement_fk');
            $table->dropColumn('replacement_outgoing_check_id');
        });
        Schema::table('outgoing_checks', function (Blueprint $table) {
            $table->dropIndex(['parent_outgoing_check_id', 'status']);
            $table->dropConstrainedForeignId('origin_installment_id');
            $table->dropConstrainedForeignId('parent_outgoing_check_id');
        });
    }
};
