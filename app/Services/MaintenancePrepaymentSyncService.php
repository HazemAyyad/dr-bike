<?php

namespace App\Services;

use App\Models\AccountingJournalEntry;
use App\Models\MaintenancePayment;
use Illuminate\Support\Facades\Schema;
use Throwable;

class MaintenancePrepaymentSyncService
{
    public function __construct(private AccountingProjectionService $projection) {}

    /** @return array{summary:array<string,int>,items:array<int,array<string,mixed>>} */
    public function run(bool $dryRun = true): array
    {
        if (! Schema::hasTable('maintenance_payments')
            || ! Schema::hasColumn('maintenance_payments', 'payment_stage')) {
            throw new \RuntimeException('The maintenance payment stage migration is not applied.');
        }

        $summary = [
            'total' => 0,
            'already_posted' => 0,
            'ready' => 0,
            'projected' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];
        $items = [];

        MaintenancePayment::query()
            ->where('payment_stage', MaintenancePayment::STAGE_PRE_DELIVERY)
            ->with(['maintenance', 'box'])
            ->orderBy('id')
            ->chunkById(200, function ($payments) use ($dryRun, &$summary, &$items) {
                foreach ($payments as $payment) {
                    $summary['total']++;
                    $alreadyPosted = AccountingJournalEntry::query()
                        ->where('source_key', 'maintenance_payment:'.$payment->id.':deposit')
                        ->where('status', AccountingJournalEntry::STATUS_POSTED)
                        ->whereNull('reverses_entry_id')
                        ->exists();

                    $status = 'ready';
                    $message = 'جاهزة للترحيل كعربون صيانة.';
                    if ($alreadyPosted) {
                        $summary['already_posted']++;
                        $status = 'already_posted';
                        $message = 'القيد موجود مسبقًا ولن يتم إنشاء قيد آخر.';
                    } else {
                        $issues = $this->preflightIssues($payment);
                        if ($issues !== []) {
                            $summary[$dryRun ? 'skipped' : 'failed']++;
                            $status = $dryRun ? 'blocked' : 'failed';
                            $message = implode(' ', $issues);
                            if (! $dryRun) {
                                $this->projection->recordFailureFor($payment, new \RuntimeException($message));
                            }
                        } elseif ($dryRun) {
                            $summary['ready']++;
                        } else {
                            try {
                                $entry = $this->projection->syncOrFail($payment);
                                if ($entry) {
                                    $summary['projected']++;
                                    $status = 'projected';
                                    $message = 'تم إنشاء القيد المحاسبي بنجاح.';
                                } else {
                                    $summary['skipped']++;
                                    $status = 'skipped';
                                    $message = 'لم تتطلب الدفعة قيدًا محاسبيًا.';
                                }
                            } catch (Throwable $e) {
                                $this->projection->recordFailureFor($payment, $e);
                                $summary['failed']++;
                                $status = 'failed';
                                $message = mb_substr($e->getMessage(), 0, 500);
                            }
                        }
                    }

                    $items[] = [
                        'payment_id' => (int) $payment->id,
                        'maintenance_id' => (int) $payment->maintenance_id,
                        'amount' => round((float) $payment->amount, 2),
                        'currency' => (string) ($payment->currency ?: $payment->box?->currency ?: 'شيكل'),
                        'box_id' => $payment->box_id ? (int) $payment->box_id : null,
                        'status' => $status,
                        'message' => $message,
                    ];
                }
            });

        return compact('summary', 'items');
    }

    /** @return array<int,string> */
    private function preflightIssues(MaintenancePayment $payment): array
    {
        $issues = [];
        if (abs((float) $payment->amount) <= 0.0001) {
            $issues[] = 'قيمة الدفعة صفر.';
        }
        if (! $payment->box_id || ! $payment->box) {
            $issues[] = 'صندوق الدفعة مفقود.';
        }
        if (! $payment->maintenance) {
            $issues[] = 'طلب الصيانة مفقود.';
        } elseif (! $payment->maintenance->customer_id && ! $payment->maintenance->seller_id) {
            $issues[] = 'طرف طلب الصيانة غير محدد.';
        }
        if ($payment->currency && $payment->box?->currency
            && trim((string) $payment->currency) !== trim((string) $payment->box->currency)) {
            $issues[] = 'عملة الدفعة لا تطابق عملة الصندوق.';
        }

        return $issues;
    }
}
