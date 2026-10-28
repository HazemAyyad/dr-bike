<?php

namespace App\Services;

use App\Models\Box;
use App\Models\BoxLog;
use App\Models\SalesDailyClosingRequest;
use App\Models\SalesDailySession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesDailyTransferCorrectionService
{
    public function __construct(
        private SalesDailySessionService $sessions,
        private BoxAccessService $boxAccess,
    ) {}

    public function canReturnToReview(SalesDailySession $session): bool
    {
        return ! $this->conflictingSessions($session)->exists();
    }

    private function conflictingSessions(SalesDailySession $session)
    {
        return SalesDailySession::query()
            ->where('session_type', $session->session_type)
            ->where('id', '!=', $session->id)
            ->where(fn ($query) => $query->where('id', '>', $session->id)
                ->orWhereIn('status', ['open', 'closing_requested']));
    }

    /**
     * Retain the original approval as a historical revision. Only the replacement
     * request is active; cash corrections are new, current-dated transfer logs.
     */
    public function correct(User $actor, int $requestId, string $action, array $targets, string $reason): SalesDailyClosingRequest
    {
        if (! $this->sessions->canReviewAllSessions($actor)) {
            $this->fail(__('messages.unauthorized'));
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 1000) {
            $this->fail('أدخل سبب التصحيح بما لا يزيد عن 1000 حرف.');
        }
        if (! in_array($action, ['redirect', 'return_to_review'], true)) {
            $this->fail('نوع تصحيح الترحيل غير صالح.');
        }

        return DB::transaction(function () use ($actor, $requestId, $action, $targets, $reason) {
            $sessionId = SalesDailyClosingRequest::query()->findOrFail($requestId)->session_id;
            $session = SalesDailySession::query()->lockForUpdate()->findOrFail($sessionId);
            $request = SalesDailyClosingRequest::query()->lockForUpdate()->findOrFail($requestId);
            $this->sessions->assertCanViewSession($actor, $session);
            if (! $session->isClosed() || $request->status !== 'approved'
                || $session->closingRequests()->where('status', 'approved')->max('id') != $request->id
                || $session->closingRequests()->where('status', 'pending')->exists()) {
                $this->fail('تغيرت حالة الإغلاق. حدّث الجلسة قبل التصحيح.');
            }
            $reverse = $action === 'return_to_review';
            if ($reverse && $this->conflictingSessions($session)->lockForUpdate()->get(['id'])->isNotEmpty()) {
                $this->fail('لا يمكن إعادة هذا الإغلاق للمراجعة بعد فتح جلسة أحدث أو بوجود جلسة نشطة أخرى. يمكنك تصحيح وجهة الترحيل.');
            }

            $transfers = $request->transfers ?? [];
            $targetMap = [];
            foreach ($targets as $target) {
                $currency = (string) ($target['currency'] ?? '');
                if (isset($targetMap[$currency])) {
                    $this->fail('حدد وجهة واحدة لكل عملة.');
                }
                $targetMap[$currency] = (int) ($target['to_box_id'] ?? 0);
            }
            $boxIds = [];
            foreach ($transfers as $transfer) {
                $boxIds[] = (int) ($transfer['from_box_id'] ?? 0);
                $boxIds[] = (int) ($transfer['to_box_id'] ?? 0);
                if (! $reverse) {
                    $boxIds[] = $targetMap[$transfer['currency'] ?? ''] ?? 0;
                }
            }
            if ($reverse) {
                foreach (array_merge($request->cash_counts ?? [], $request->sales_orders_cash_counts ?? []) as $count) {
                    $boxIds[] = (int) ($count['daily_box_id'] ?? 0);
                }
            }
            $boxes = Box::query()->whereIn('id', array_unique($boxIds))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($reverse) {
                foreach (array_merge($request->cash_counts ?? [], $request->sales_orders_cash_counts ?? []) as $count) {
                    $daily = $boxes->get((int) ($count['daily_box_id'] ?? 0));
                    if (! $daily || round((float) $daily->total, 2) !== round((float) ($count['float_to_keep'] ?? 0), 2)) {
                        $this->fail('تغير رصيد الصندوق اليومي بعد الإغلاق. يمكنك تصحيح وجهة الترحيل دون إعادة الطلب للمراجعة.');
                    }
                }
            }
            $moves = [];
            $corrected = [];
            foreach ($transfers as $transfer) {
                $amount = round((float) ($transfer['amount'] ?? 0), 2);
                $currency = (string) ($transfer['currency'] ?? '');
                $daily = $boxes->get((int) ($transfer['from_box_id'] ?? 0));
                $oldTarget = $boxes->get((int) ($transfer['to_box_id'] ?? 0));
                $newTarget = $reverse ? $daily : $boxes->get($targetMap[$currency] ?? 0);
                if ($amount <= 0 || ! $daily || ! $oldTarget || ! $newTarget
                    || $daily->id === $oldTarget->id
                    || $oldTarget->currency !== $currency || $newTarget->currency !== $currency
                    || $daily->currency !== $currency) {
                    $this->fail('بيانات الترحيل أو عملة الصندوق غير صالحة.');
                }
                if (! $this->boxAccess->canAccess($actor, $oldTarget->id)
                    || (! $reverse && ! $this->boxAccess->canAccess($actor, $newTarget->id))) {
                    $this->fail(__('messages.unauthorized'));
                }
                if (! $reverse && (! $newTarget->is_shown || in_array($newTarget->type, [
                    'daily_sales', 'daily_sales_orders', 'daily_maintenance',
                ], true))) {
                    $this->fail('اختر صندوق ترحيل دائمًا ومتاحًا.');
                }
                if ($oldTarget->id !== $newTarget->id) {
                    if (round((float) $oldTarget->total, 2) < $amount) {
                        $this->fail('رصيد '.$oldTarget->name.' لا يكفي لعكس مبلغ الترحيل.');
                    }
                    $oldTarget->total = round((float) $oldTarget->total - $amount, 2);
                    $newTarget->total = round((float) $newTarget->total + $amount, 2);
                    $moves[] = [$oldTarget, $newTarget, $amount];
                }
                $corrected[] = array_merge($transfer, ['to_box_id' => $newTarget->id]);
            }
            if (! $reverse && $moves === []) {
                $this->fail('اختر صندوق وجهة مختلفًا لتصحيح الترحيل.');
            }

            $label = $reverse ? 'عكس الإغلاق وإعادة الطلب للمراجعة' : 'تصحيح وجهة الترحيل';
            $note = $label.' عن طلب #'.$request->id.' — '.$reason;
            $replacement = SalesDailyClosingRequest::create([
                'session_id' => $session->id,
                'requested_by_user_id' => $reverse ? $actor->id : $request->requested_by_user_id,
                'requested_at' => $reverse ? now() : $request->requested_at,
                'status' => $reverse ? 'pending' : 'approved',
                'reviewed_by_user_id' => $reverse ? null : $actor->id,
                'reviewed_at' => $reverse ? null : now(),
                'review_notes' => $note,
                'instant_sales_count' => $request->instant_sales_count,
                'profit_sales_count' => $request->profit_sales_count,
                'cash_counts' => $request->cash_counts,
                'sales_orders_cash_counts' => $request->sales_orders_cash_counts,
                'late_close_reason' => $request->late_close_reason,
                'transfers' => $reverse ? [] : $corrected,
            ]);
            foreach ($boxes as $box) {
                if ($box->isDirty('total')) {
                    $box->save();
                }
            }
            foreach ($moves as [$from, $to, $amount]) {
                BoxLog::create([
                    'from_box_id' => $from->id, 'to_box_id' => $to->id,
                    'description' => $label,
                    'note' => 'جلسة #'.$session->id.' — '.$note,
                    'value' => $amount, 'type' => 'transfer',
                    'reason_code' => 'box_transfer', 'created_by' => $actor->id,
                    'source_type' => 'sales_daily_closing_request', 'source_id' => $replacement->id,
                ]);
            }
            $request->update([
                'status' => $reverse ? 'reversed' : 'superseded',
                'review_notes' => trim(($request->review_notes ?? '')."\n".$label.' بواسطة '.$actor->name
                    .' (#'.$actor->id.') في '.now()->toDateTimeString().' — '.$reason),
            ]);
            if ($reverse) {
                $session->update(['status' => 'closing_requested', 'closed_at' => null, 'closed_by_user_id' => null]);
            }

            return $replacement->fresh();
        }, 3);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['correction' => [$message]]);
    }
}
