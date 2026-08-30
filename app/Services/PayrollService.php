<?php

namespace App\Services;

use App\Http\Controllers\API\BoxLogs;
use App\Models\Box;
use App\Models\EmployeeAdvanceApplication;
use App\Models\EmployeeDetail;
use App\Models\EmployeeOrder;
use App\Models\EmployeeSalaryPeriod;
use App\Models\Expense;
use App\Models\SalaryPaymentBatch;
use App\Models\SalaryPaymentItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PayrollService
{
    public function __construct(
        private PayrollCalculationService $calculator,
        private ExpenseBoxAccessService $boxAccess,
        private EmployeeNotificationService $employeeNotifications
    ) {}

    /** @param array<int, array{employee_id:int,amount_paid?:float|int|string}> $items */
    public function pay(User $actor, string $month, int $boxId, string $paymentDate, array $items, ?string $notes = null): SalaryPaymentBatch
    {
        if (! $this->boxAccess->canUse($actor, $boxId)) {
            throw ValidationException::withMessages(['box_id' => ['الصندوق غير ظاهر لك أو أن جلسته اليومية مغلقة.']]);
        }

        $employeeIds = collect($items)->pluck('employee_id')->map(fn ($id) => (int) $id);
        if ($employeeIds->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['items' => ['لا يمكن تكرار الموظف في نفس دفعة الرواتب.']]);
        }

        $notifications = [];
        $batch = DB::transaction(function () use ($actor, $month, $boxId, $paymentDate, $items, $notes, &$notifications) {
            $box = Box::query()->lockForUpdate()->findOrFail($boxId);
            if ($box->currency !== 'شيكل') {
                throw ValidationException::withMessages(['box_id' => [__('messages.box_must_be_shekel')]]);
            }

            $monthDate = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
            $batch = SalaryPaymentBatch::create([
                'salary_month' => $monthDate->toDateString(),
                'box_id' => $box->id,
                'payment_date' => $paymentDate,
                'notes' => $notes,
                'created_by_user_id' => $actor->id,
                'status' => 'completed',
            ]);

            $grossTotal = 0.0;
            $advancesTotal = 0.0;
            $cashTotal = 0.0;

            foreach ($items as $requested) {
                $employee = EmployeeDetail::with('user:id,name')->lockForUpdate()->findOrFail((int) $requested['employee_id']);
                $calculation = $this->calculator->calculate($employee, $month);
                $period = EmployeeSalaryPeriod::query()
                    ->where('employee_id', $employee->id)
                    ->whereDate('salary_month', $monthDate->toDateString())
                    ->lockForUpdate()
                    ->first();

                $newPeriod = ! $period;
                if ($newPeriod) {
                    $period = EmployeeSalaryPeriod::create([
                        'employee_id' => $employee->id,
                        'salary_month' => $monthDate->toDateString(),
                        'normal_salary' => $calculation['normal_salary'],
                        'overtime_salary' => $calculation['overtime_salary'],
                        'bonuses' => $calculation['bonuses'],
                        'gross_entitlement' => $calculation['gross_entitlement'],
                        'advances_applied' => 0,
                        'total_paid' => 0,
                        'remaining' => $calculation['gross_entitlement'],
                        'status' => 'calculated',
                        'calculation_snapshot' => $calculation,
                    ]);

                    $applied = $this->applyAdvances($employee, $period, $monthDate->copy()->endOfMonth());
                    $period->advances_applied = $applied;
                    $period->remaining = max(0, round((float) $period->gross_entitlement - $applied, 2));
                    $period->save();

                    $expense = Expense::create([
                        'name' => 'راتب شهر '.$month.' - '.($employee->user?->name ?? $employee->id),
                        'price' => $period->gross_entitlement,
                        'expense_type' => 'salary',
                        'expense_date' => $paymentDate,
                        'employee_id' => $employee->id,
                        'salary_period_id' => $period->id,
                        'created_by_user_id' => $actor->id,
                        'notes' => 'استحقاق راتب شهر '.$month,
                        'media' => [],
                        'invoice_img' => [],
                    ]);
                    $period->recognized_expense_id = $expense->id;
                    $period->save();
                }

                $remainingBefore = round((float) $period->remaining, 2);
                $amount = array_key_exists('amount_paid', $requested)
                    ? round((float) $requested['amount_paid'], 2)
                    : $remainingBefore;
                $zeroCashSettlement = $newPeriod && $remainingBefore <= 0 && $amount === 0.0;
                if ((! $zeroCashSettlement && $amount <= 0) || $amount > $remainingBefore) {
                    throw ValidationException::withMessages([
                        'items' => ["مبلغ {$employee->user?->name} يجب أن يكون أكبر من صفر ولا يتجاوز المتبقي {$remainingBefore}."],
                    ]);
                }

                $remainingAfter = round($remainingBefore - $amount, 2);
                $item = SalaryPaymentItem::create([
                    'batch_id' => $batch->id,
                    'salary_period_id' => $period->id,
                    'employee_id' => $employee->id,
                    'amount_paid' => $amount,
                    'remaining_before' => $remainingBefore,
                    'remaining_after' => $remainingAfter,
                    'receipt_status' => 'pending',
                ]);
                $item->receipt_hash = hash('sha256', json_encode([
                    'payment_id' => $item->id,
                    'period_id' => $period->id,
                    'employee_id' => $employee->id,
                    'month' => $month,
                    'amount' => number_format($amount, 2, '.', ''),
                    'snapshot' => $period->calculation_snapshot,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $item->save();

                $period->total_paid = round((float) $period->total_paid + $amount, 2);
                $period->remaining = $remainingAfter;
                $period->status = $remainingAfter <= 0 ? 'paid' : 'partially_paid';
                $period->save();

                $grossTotal += (float) $period->gross_entitlement;
                $advancesTotal += (float) $period->advances_applied;
                $cashTotal += $amount;
                $notifications[] = $item->id;
            }

            if ($cashTotal > (float) $box->total) {
                throw ValidationException::withMessages(['box_id' => [__('messages.box_out_of_money')]]);
            }

            $boxLog = null;
            if ($cashTotal > 0) {
                $box->decrement('total', $cashTotal);
                $box->refresh();
                $boxLog = BoxLogs::createBoxLog(
                    $box,
                    'صرف دفعة رواتب رقم '.$batch->id.' لشهر '.$month,
                    'minus',
                    $cashTotal
                );
            }

            $batch->update([
                'box_log_id' => $boxLog?->id,
                'gross_total' => round($grossTotal, 2),
                'advances_total' => round($advancesTotal, 2),
                'cash_total' => round($cashTotal, 2),
            ]);

            return $batch;
        }, 3);

        foreach ($notifications as $itemId) {
            $item = SalaryPaymentItem::with(['employee.user', 'salaryPeriod'])->find($itemId);
            if ($item) {
                try {
                    $this->employeeNotifications->notifySalaryPaid($item);
                } catch (\Throwable $error) {
                    Log::error('Salary paid notification failed after committed payroll', [
                        'salary_payment_item_id' => $item->id,
                        'employee_id' => $item->employee_id,
                        'error' => $error->getMessage(),
                    ]);
                }
            }
        }

        return $batch->load(['box:id,name,currency,total', 'creator:id,name', 'items.employee.user:id,name', 'items.salaryPeriod']);
    }

    private function applyAdvances(EmployeeDetail $employee, EmployeeSalaryPeriod $period, Carbon $through): float
    {
        $remainingCapacity = (float) $period->gross_entitlement;
        $appliedTotal = 0.0;
        $advances = EmployeeOrder::query()
            ->where('employee_id', $employee->id)
            ->where('type', 'loan')
            ->whereIn('status', ['approved', 'paid'])
            ->where('created_at', '<=', $through->endOfDay())
            ->withSum('salaryApplications as applied_amount', 'amount')
            ->orderBy('created_at')->orderBy('id')->lockForUpdate()->get();

        foreach ($advances as $advance) {
            if ($remainingCapacity <= 0) break;
            $outstanding = max(0, round((float) $advance->loan_value - (float) ($advance->applied_amount ?? 0), 2));
            $amount = min($remainingCapacity, $outstanding);
            if ($amount <= 0) continue;

            EmployeeAdvanceApplication::create([
                'employee_order_id' => $advance->id,
                'salary_period_id' => $period->id,
                'amount' => $amount,
            ]);
            $remainingCapacity -= $amount;
            $appliedTotal += $amount;
        }

        if ($appliedTotal > 0) {
            $employee->debts = max(0, round((float) $employee->debts - $appliedTotal, 2));
            $employee->save();
        }

        return round($appliedTotal, 2);
    }
}
