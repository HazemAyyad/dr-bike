<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('maintenance_payments')) {
            return;
        }

        if (! Schema::hasColumn('maintenance_payments', 'payment_stage')) {
            Schema::table('maintenance_payments', function (Blueprint $table) {
                $table->string('payment_stage', 32)
                    ->nullable()
                    ->after('instant_sale_id')
                    ->index();
            });
        }

        if (! Schema::hasTable('maintenance')) {
            return;
        }

        // Only still-open, positive, unlinked payments have enough evidence to
        // be classified safely. Historical delivered payments remain NULL and
        // are treated as legacy/unknown rather than guessed.
        DB::table('maintenance_payments as payments')
            ->join('maintenance as maintenance', 'maintenance.id', '=', 'payments.maintenance_id')
            ->whereNull('payments.payment_stage')
            ->whereNull('payments.instant_sale_id')
            ->where('payments.amount', '>', 0)
            ->where('payments.method', '!=', 'cancellation_reversal')
            ->where('maintenance.status', '!=', 'delivered')
            ->when(
                Schema::hasColumn('maintenance', 'deleted_at'),
                fn ($query) => $query->whereNull('maintenance.deleted_at'),
            )
            ->select('payments.id')
            ->orderBy('payments.id')
            ->chunkById(500, function ($rows) {
                DB::table('maintenance_payments')
                    ->whereIn('id', $rows->pluck('id'))
                    ->update([
                        'payment_stage' => 'pre_delivery',
                    ]);
            }, 'payments.id', 'id');
    }

    public function down(): void
    {
        // Deliberately non-destructive: once a payment stage is established it
        // is accounting evidence and must not be erased by a rollback.
    }
};
