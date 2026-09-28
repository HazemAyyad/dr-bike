<?php

namespace App\Services;

use App\Models\OutgoingCheck;
use App\Models\OutgoingCheckInstallment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OutgoingCheckScheduleService
{
    public function replace(OutgoingCheck $parent, array $rows): OutgoingCheck
    {
        return DB::transaction(function () use ($parent, $rows) {
            $parent = OutgoingCheck::query()->lockForUpdate()->findOrFail($parent->id);
            if (! in_array($parent->status, ['restructured', 'restructured_parent'], true)) {
                throw ValidationException::withMessages(['outgoing_check_id' => ['هذا الشيك لا يحتوي جدولة قابلة للتعديل.']]);
            }
            $children = $parent->scheduledChecks()->lockForUpdate()->get();
            if ($children->contains(fn ($child) => $child->status !== 'not_cashed' || $child->settlements()->exists())) {
                throw ValidationException::withMessages(['outgoing_check_id' => ['لا يمكن تعديل الجدولة بعد التصرف بأحد الشيكات التابعة.']]);
            }
            $oldFrontImages = $children->pluck('img')->filter()->unique()->values()->all();
            $oldBackImages = $children->pluck('back_image')->filter()->unique()->values()->all();

            $remaining = round((float) $parent->remaining_amount, 4);
            $sum = round((float) collect($rows)->sum(fn ($row) => (float) $row['amount']), 4);
            if (abs($sum - $remaining) > 0.0001) {
                throw ValidationException::withMessages(['installments' => ['مجموع الجدولة يجب أن يساوي المتبقي '.$remaining.'.']]);
            }
            $types = collect($rows)->pluck('instrument_type')->unique();

            OutgoingCheck::withoutEvents(fn () => $parent->scheduledChecks()->delete());
            $parent->installments()->delete();
            foreach ($rows as $row) {
                $installment = OutgoingCheckInstallment::query()->create([
                    'outgoing_check_id' => $parent->id,
                    'amount' => $row['amount'], 'due_date' => $row['due_date'],
                    'instrument_type' => $row['instrument_type'],
                    'check_id' => $row['check_id'] ?? null, 'bank_name' => $row['bank_name'] ?? null,
                    'notes' => $row['notes'] ?? null,
                ]);
                $child = OutgoingCheck::query()->create([
                    'parent_outgoing_check_id' => $parent->id, 'origin_installment_id' => $installment->id,
                    'customer_id' => $parent->customer_id, 'seller_id' => $parent->seller_id,
                    'status' => 'not_cashed', 'settlement_status' => 'unpaid',
                    'total' => $row['amount'], 'due_date' => $row['due_date'], 'currency' => $parent->currency,
                    'check_id' => $row['instrument_type'] === 'same_check' ? $parent->check_id : $row['check_id'],
                    'bank_name' => $row['instrument_type'] === 'same_check' ? $parent->bank_name : $row['bank_name'],
                    'img' => $row['instrument_type'] === 'same_check' ? $parent->img : ($row['img'] ?? $parent->img),
                    'back_image' => $row['instrument_type'] === 'same_check' ? $parent->back_image : ($row['back_image'] ?? $parent->back_image),
                    'notes' => $row['notes'] ?? $parent->notes, 'batch_number' => $parent->batch_number,
                ]);
                $installment->update(['replacement_outgoing_check_id' => $child->id, 'status' => 'materialized']);
            }
            $parent->update(['status' => $types->contains('replacement_check') ? 'restructured_parent' : 'restructured']);
            DB::afterCommit(function () use ($oldFrontImages, $oldBackImages) {
                foreach ($oldFrontImages as $file) {
                    if (! OutgoingCheck::query()->where('img', $file)->exists()) {
                        @unlink(public_path('OutgoingChecksImages/'.$file));
                    }
                }
                foreach ($oldBackImages as $file) {
                    if (! OutgoingCheck::query()->where('back_image', $file)->exists()) {
                        @unlink(public_path('OutgoingChecksImages/back/'.$file));
                    }
                }
            });
            return $parent->fresh()->load(['settlements', 'installments.replacementCheck']);
        }, 3);
    }
}
