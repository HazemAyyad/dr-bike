<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('outgoing_check_installments', 'replacement_outgoing_check_id')
            || ! Schema::hasColumn('outgoing_checks', 'parent_outgoing_check_id')) {
            return;
        }

        DB::table('outgoing_check_installments')
            ->where('instrument_type', 'same_check')
            ->where('status', 'pending')
            ->whereNull('replacement_outgoing_check_id')
            ->orderBy('id')
            ->chunkById(100, function ($installments) {
                foreach ($installments as $installment) {
                    $parent = DB::table('outgoing_checks')
                        ->where('id', $installment->outgoing_check_id)
                        ->where('status', 'restructured')
                        ->first();
                    if (! $parent) {
                        continue;
                    }

                    $existingChildId = DB::table('outgoing_checks')
                        ->where('origin_installment_id', $installment->id)
                        ->value('id');

                    $childId = $existingChildId ?: DB::table('outgoing_checks')->insertGetId([
                        'parent_outgoing_check_id' => $parent->id,
                        'origin_installment_id' => $installment->id,
                        'customer_id' => $parent->customer_id,
                        'seller_id' => $parent->seller_id,
                        'status' => 'not_cashed',
                        'settlement_status' => 'unpaid',
                        'total' => $installment->amount,
                        'due_date' => $installment->due_date,
                        'currency' => $parent->currency,
                        'check_id' => $parent->check_id,
                        'bank_name' => $parent->bank_name,
                        'img' => $parent->img,
                        'back_image' => $parent->back_image,
                        'notes' => $installment->notes ?: $parent->notes,
                        'batch_number' => $parent->batch_number,
                        'created_at' => $installment->created_at ?: now(),
                        'updated_at' => now(),
                    ]);

                    DB::table('outgoing_check_installments')
                        ->where('id', $installment->id)
                        ->update([
                            'replacement_outgoing_check_id' => $childId,
                            'status' => 'materialized',
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('outgoing_check_installments')
            ->where('instrument_type', 'same_check')
            ->where('status', 'materialized')
            ->whereNotNull('replacement_outgoing_check_id')
            ->orderBy('id')
            ->chunkById(100, function ($installments) {
                foreach ($installments as $installment) {
                    $child = DB::table('outgoing_checks')
                        ->where('id', $installment->replacement_outgoing_check_id)
                        ->first();
                    if (! $child || $child->status !== 'not_cashed') {
                        continue;
                    }
                    $hasSettlement = DB::table('outgoing_check_settlements')
                        ->where('outgoing_check_id', $child->id)
                        ->exists();
                    if ($hasSettlement) {
                        continue;
                    }

                    DB::table('outgoing_checks')->where('id', $child->id)->delete();
                    DB::table('outgoing_check_installments')->where('id', $installment->id)->update([
                        'replacement_outgoing_check_id' => null,
                        'status' => 'pending',
                        'updated_at' => now(),
                    ]);
                }
            });
    }
};
