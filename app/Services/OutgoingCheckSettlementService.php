<?php

namespace App\Services;

use App\Http\Controllers\API\BoxLogs;
use App\Models\Box;
use App\Models\OutgoingCheck;
use App\Models\OutgoingCheckInstallment;
use App\Models\OutgoingCheckSettlement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OutgoingCheckSettlementService
{
    public function settle(array $data, ?int $userId): OutgoingCheck
    {
        return DB::transaction(function () use ($data, $userId) {
            $existing = OutgoingCheckSettlement::query()
                ->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                return $existing->check()->with(['settlements', 'installments'])->firstOrFail();
            }

            $check = OutgoingCheck::query()->lockForUpdate()->findOrFail($data['outgoing_check_id']);
            $box = Box::query()->lockForUpdate()->findOrFail($data['box_id']);
            $settledBefore = (float) $check->settlements()->sum('amount');
            $remainingBefore = round((float) $check->total - $settledBefore, 4);
            $amount = round((float) $data['amount'], 4);

            if (! in_array($check->status, ['not_cashed', 'cashed_to_person', 'partially_settled', 'restructured'], true) || $remainingBefore <= 0) {
                throw ValidationException::withMessages(['outgoing_check_id' => ['الشيك غير متاح للتسديد.']]);
            }
            if ($check->currency !== $box->currency) {
                throw ValidationException::withMessages(['box_id' => [__('messages.must_be_same_currency_check')]]);
            }
            if ($amount <= 0 || $amount > $remainingBefore) {
                throw ValidationException::withMessages(['amount' => ['المبلغ المدفوع يجب أن يكون أكبر من صفر ولا يتجاوز المتبقي.']]);
            }
            if ($amount > (float) $box->total) {
                throw ValidationException::withMessages(['box_id' => [__('messages.box_out_of_money')]]);
            }

            $remainingAfter = round($remainingBefore - $amount, 4);
            $installments = collect($data['installments'] ?? []);
            $hasPendingSchedule = $check->installments()->where('status', 'pending')->exists();
            if ($hasPendingSchedule && $remainingAfter > 0 && $installments->isEmpty()) {
                throw ValidationException::withMessages(['installments' => ['يجب تحديث جدول الدفعات ليطابق المتبقي الجديد.']]);
            }
            if ($installments->isNotEmpty()) {
                $scheduled = round((float) $installments->sum(fn ($row) => (float) $row['amount']), 4);
                if (abs($scheduled - $remainingAfter) > 0.0001) {
                    throw ValidationException::withMessages(['installments' => ['مجموع الدفعات الجديدة يجب أن يساوي المبلغ المتبقي.']]);
                }
            } elseif ($remainingAfter <= 0) {
                $check->installments()->where('status', 'pending')->update(['status' => 'paid']);
            }

            $settlement = OutgoingCheckSettlement::query()->create([
                'outgoing_check_id' => $check->id,
                'box_id' => $box->id,
                'amount' => $amount,
                'paid_at' => $data['paid_at'],
                'idempotency_key' => $data['idempotency_key'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            $box->decrement('total', $amount);
            BoxLogs::createBoxLog($box->fresh(), 'تسديد جزئي للشيك الصادر رقم '.($check->check_id ?: $check->id), 'minus', $amount);

            if ($installments->isNotEmpty()) {
                $check->installments()->where('status', 'pending')->delete();
                foreach ($installments as $row) {
                    OutgoingCheckInstallment::query()->create([
                        'outgoing_check_id' => $check->id,
                        'amount' => $row['amount'],
                        'due_date' => $row['due_date'],
                        'instrument_type' => $row['instrument_type'],
                        'check_id' => $row['check_id'] ?? null,
                        'bank_name' => $row['bank_name'] ?? null,
                        'notes' => $row['notes'] ?? null,
                    ]);
                }
            }

            $check->update([
                'status' => $remainingAfter <= 0 ? 'settled' : ($installments->isNotEmpty() ? 'restructured' : 'partially_settled'),
                'settlement_status' => $remainingAfter <= 0 ? 'fully_paid' : ($installments->isNotEmpty() ? 'partially_paid_restructured' : 'partially_paid'),
                'restructured_at' => $installments->isNotEmpty() ? now() : $check->restructured_at,
                'box_id' => $box->id,
            ]);

            return $check->fresh()->load(['settlements', 'installments']);
        }, 3);
    }
}
