<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\EmployeeDetail;
use App\Models\EmployeeSalaryPeriod;
use App\Models\EmployeeSignature;
use App\Models\SalaryPaymentBatch;
use App\Models\SalaryPaymentItem;
use App\Services\AdminNotificationService;
use App\Services\ExpenseBoxAccessService;
use App\Services\EmployeeSignatureService;
use App\Services\PayrollCalculationService;
use App\Services\PayrollService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class SalaryPayrollController extends Controller
{
    public function employees(Request $request)
    {
        $employees = EmployeeDetail::query()
            ->with('user:id,name')
            ->where('is_suspended', false)
            ->orderByDesc('id')
            ->get()
            ->map(fn (EmployeeDetail $employee) => [
                'id' => $employee->id,
                'name' => $employee->user?->name,
                'job_title' => $employee->job_title,
                'employee_img' => $employee->employee_img,
            ]);

        return response()->json(['status' => 'success', 'employees' => $employees]);
    }

    public function boxes(Request $request, ExpenseBoxAccessService $access)
    {
        $openIds = $access->openDailyBoxIds();
        $boxes = $access->availableBoxes($request->user())
            ->filter(fn ($box) => $box->currency === 'شيكل')
            ->map(fn ($box) => [
                'id' => $box->id,
                'name' => $box->name,
                'total' => round((float) $box->total, 2),
                'currency' => $box->currency,
                'type' => $box->type,
                'is_daily_open' => $openIds->contains((int) $box->id),
            ])->values();

        return response()->json(['status' => 'success', 'boxes' => $boxes]);
    }

    public function preview(Request $request, PayrollCalculationService $calculator)
    {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['required', 'integer', 'distinct', 'exists:employee_details,id'],
        ]);

        $employees = EmployeeDetail::with('user:id,name')->whereIn('id', $data['employee_ids'])->get()->keyBy('id');
        $rows = collect($data['employee_ids'])->map(fn ($id) => $calculator->calculate($employees[(int) $id], $data['month']))->values();

        return response()->json([
            'status' => 'success',
            'month' => $data['month'],
            'employees' => $rows,
            'summary' => [
                'gross_total' => round((float) $rows->sum('gross_entitlement'), 2),
                'advances_total' => round((float) $rows->sum('advances_to_apply'), 2),
                'paid_total' => round((float) $rows->sum('total_paid'), 2),
                'remaining_total' => round((float) $rows->sum('remaining'), 2),
            ],
        ]);
    }

    public function pay(Request $request, PayrollService $payroll)
    {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'box_id' => ['required', 'integer', 'exists:boxes,id'],
            'payment_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.employee_id' => ['required', 'integer', 'distinct', 'exists:employee_details,id'],
            'items.*.amount_paid' => ['nullable', 'numeric', 'gte:0'],
        ]);

        $batch = $payroll->pay(
            $request->user(),
            $data['month'],
            (int) $data['box_id'],
            $data['payment_date'] ?? now()->toDateString(),
            $data['items'],
            $data['notes'] ?? null
        );

        return response()->json([
            'status' => 'success',
            'message' => 'تم صرف الرواتب وإرسال طلبات تأكيد الاستلام للموظفين.',
            'batch' => $batch,
        ]);
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'employee_id' => ['nullable', 'integer', 'exists:employee_details,id'],
            'status' => ['nullable', 'in:calculated,partially_paid,paid,cancelled'],
            'receipt_status' => ['nullable', 'in:pending,received,disputed'],
        ]);

        $periods = EmployeeSalaryPeriod::query()
            ->with(['employee.user:id,name', 'payments.batch:id,payment_date,box_id,created_by_user_id', 'payments.batch.box:id,name,currency'])
            ->when($data['month'] ?? null, fn ($q, $month) => $q->whereDate('salary_month', $month.'-01'))
            ->when($data['employee_id'] ?? null, fn ($q, $id) => $q->where('employee_id', $id))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['receipt_status'] ?? null, fn ($q, $status) => $q->whereHas('payments', fn ($p) => $p->where('receipt_status', $status)))
            ->latest('salary_month')->latest('id')->paginate(20);

        return response()->json(['status' => 'success', 'periods' => $periods]);
    }

    public function showPeriod(int $period)
    {
        $row = EmployeeSalaryPeriod::query()
            ->with([
                'employee.user:id,name',
                'expense:id,name,price,expense_type,expense_date,salary_period_id',
                'advanceApplications.order',
                'payments' => fn ($query) => $query->latest('id'),
                'payments.batch.creator:id,name',
                'payments.batch.box:id,name,currency',
            ])
            ->findOrFail($period);

        return response()->json(['status' => 'success', 'period' => $row]);
    }

    public function report(Request $request)
    {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'status' => ['nullable', 'in:calculated,partially_paid,paid,cancelled'],
            'receipt_status' => ['nullable', 'in:pending,received,disputed'],
        ]);

        $periods = EmployeeSalaryPeriod::query()
            ->with([
                'employee.user:id,name',
                'payments:id,salary_period_id,amount_paid,receipt_status,received_at,disputed_at',
            ])
            ->whereDate('salary_month', $data['month'].'-01')
            ->when($data['status'] ?? null, fn ($query, $status) =>
                $query->where('status', $status))
            ->when($data['receipt_status'] ?? null, fn ($query, $status) =>
                $query->whereHas('payments', fn ($payments) =>
                    $payments->where('receipt_status', $status)))
            ->orderBy('employee_id')
            ->get();

        $rows = $periods->map(function (EmployeeSalaryPeriod $period) {
            $payments = $period->payments;

            return [
                'period_id' => $period->id,
                'employee_id' => $period->employee_id,
                'employee_name' => $period->employee?->user?->name,
                'salary_month' => optional($period->salary_month)->format('Y-m'),
                'gross_entitlement' => (float) $period->gross_entitlement,
                'advances_applied' => (float) $period->advances_applied,
                'total_paid' => (float) $period->total_paid,
                'remaining' => (float) $period->remaining,
                'status' => $period->status,
                'payments_count' => $payments->count(),
                'received_count' => $payments->where('receipt_status', 'received')->count(),
                'pending_count' => $payments->where('receipt_status', 'pending')->count(),
                'disputed_count' => $payments->where('receipt_status', 'disputed')->count(),
            ];
        })->values();

        return response()->json([
            'status' => 'success',
            'month' => $data['month'],
            'generated_at' => now()->toIso8601String(),
            'summary' => [
                'employees_count' => $periods->count(),
                'gross_total' => round((float) $periods->sum('gross_entitlement'), 2),
                'advances_total' => round((float) $periods->sum('advances_applied'), 2),
                'paid_total' => round((float) $periods->sum('total_paid'), 2),
                'remaining_total' => round((float) $periods->sum('remaining'), 2),
                'received_count' => $periods->sum(fn ($period) =>
                    $period->payments->where('receipt_status', 'received')->count()),
                'pending_count' => $periods->sum(fn ($period) =>
                    $period->payments->where('receipt_status', 'pending')->count()),
                'disputed_count' => $periods->sum(fn ($period) =>
                    $period->payments->where('receipt_status', 'disputed')->count()),
            ],
            'rows' => $rows,
        ]);
    }

    public function showBatch(int $batch)
    {
        $row = SalaryPaymentBatch::with([
            'box:id,name,currency,total', 'creator:id,name',
            'items.employee.user:id,name', 'items.salaryPeriod.advanceApplications.order',
        ])->findOrFail($batch);

        return response()->json(['status' => 'success', 'batch' => $row]);
    }

    public function pendingReceipts(Request $request)
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 403);

        $items = SalaryPaymentItem::with(['salaryPeriod', 'batch.creator:id,name', 'batch.box:id,name,currency'])
            ->where('employee_id', $employee->id)
            ->where('receipt_status', 'pending')
            ->latest()->get();

        return response()->json(['status' => 'success', 'count' => $items->count(), 'receipts' => $items]);
    }

    public function myReceipts(Request $request)
    {
        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'status' => ['nullable', 'in:pending,received,disputed'],
        ]);
        $employee = $request->user()->employee;
        abort_unless($employee, 403);

        $items = SalaryPaymentItem::query()
            ->with(['salaryPeriod', 'batch.creator:id,name', 'batch.box:id,name,currency'])
            ->where('employee_id', $employee->id)
            ->when($data['month'] ?? null, function ($query, string $month) {
                $query->whereHas('salaryPeriod', fn ($period) =>
                    $period->whereDate('salary_month', $month.'-01'));
            })
            ->when($data['status'] ?? null, fn ($query, $status) =>
                $query->where('receipt_status', $status))
            ->latest('id')
            ->paginate(20);

        return response()->json(['status' => 'success', 'receipts' => $items]);
    }

    public function employeeReceipt(Request $request, int $item)
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 403);
        $payment = SalaryPaymentItem::with(['employee.user:id,name', 'salaryPeriod', 'batch.creator:id,name', 'batch.box:id,name,currency'])
            ->where('employee_id', $employee->id)->findOrFail($item);

        return response()->json(['status' => 'success', 'receipt' => $this->receiptPayload($payment)]);
    }

    public function adminReceipt(int $item)
    {
        $payment = SalaryPaymentItem::with(['employee.user:id,name', 'salaryPeriod', 'batch.creator:id,name', 'batch.box:id,name,currency'])->findOrFail($item);

        return response()->json(['status' => 'success', 'receipt' => $this->receiptPayload($payment)]);
    }

    public function verify(Request $request, string $hash)
    {
        $payment = SalaryPaymentItem::with(['employee.user:id,name', 'salaryPeriod:id,salary_month'])
            ->where('receipt_hash', $hash)
            ->firstOrFail();
        $user = $request->user();
        $isOwner = (int) ($user?->employee?->id ?? 0) === (int) $payment->employee_id;
        $actorEmployee = $user?->employee;
        $canViewPayroll = $user?->type === 'admin' || ($actorEmployee && $actorEmployee->permissions()
            ->whereHas('permission', fn ($query) => $query->whereIn('name_en', [
                'Employees Financial View',
                'Employees Salary Pay',
            ]))->exists());
        abort_unless($isOwner || $canViewPayroll, 403);

        return response()->json([
            'status' => 'success',
            'valid' => true,
            'receipt' => [
                'number' => 'PAYROLL-'.$payment->id,
                'employee_name' => $payment->employee?->user?->name,
                'salary_month' => optional($payment->salaryPeriod?->salary_month)->format('Y-m'),
                'amount_paid' => number_format((float) $payment->amount_paid, 2, '.', ''),
                'receipt_status' => $payment->receipt_status,
                'received_at' => $payment->received_at?->toIso8601String(),
                'issuer' => 'Doctor Bike',
            ],
        ]);
    }

    public function acknowledge(
        Request $request,
        int $item,
        AdminNotificationService $notifications,
        EmployeeSignatureService $signatures
    )
    {
        $data = $request->validate([
            'signature_id' => ['nullable', 'integer', 'exists:employee_signatures,id'],
            'signature' => ['required_without:signature_id', 'nullable', 'string', 'max:14000000'],
            'signature_name' => ['nullable', 'string', 'max:100'],
            'signature_source' => ['nullable', 'in:manual,camera,upload'],
            'save_signature' => ['nullable', 'boolean'],
            'make_default' => ['nullable', 'boolean'],
            'device' => ['nullable', 'string', 'max:500'],
        ]);
        $employee = $request->user()->employee;
        abort_unless($employee, 403);
        $payment = SalaryPaymentItem::where('employee_id', $employee->id)->findOrFail($item);
        if ($payment->receipt_status === 'received') {
            return response()->json(['status' => 'success', 'message' => 'تم تأكيد الاستلام مسبقاً.', 'receipt' => $payment]);
        }

        if (! empty($data['signature_id'])) {
            $stored = EmployeeSignature::query()
                ->where('employee_id', $employee->id)
                ->findOrFail((int) $data['signature_id']);
            $snapshot = $signatures->snapshotStored($employee, $stored);
        } else {
            $name = trim((string) ($data['signature_name'] ?? 'توقيع الراتب'));
            $source = (string) ($data['signature_source'] ?? 'manual');
            if ((bool) ($data['save_signature'] ?? false)) {
                $stored = $signatures->create(
                    $employee,
                    $name,
                    $source,
                    (string) $data['signature'],
                    (bool) ($data['make_default'] ?? false)
                );
                $snapshot = $signatures->snapshotStored($employee, $stored);
            } else {
                $snapshot = $signatures->snapshotInline(
                    (string) $data['signature'],
                    $name,
                    $source
                );
            }
        }
        $payment->update([
            'receipt_status' => 'received',
            'received_at' => now(),
            'employee_signature_path' => $snapshot['path'],
            'employee_signature_original_path' => $snapshot['original_path'],
            'employee_signature_id' => $snapshot['id'],
            'employee_signature_name' => $snapshot['name'],
            'employee_signature_source' => $snapshot['source'],
            'employee_signature_hash' => $snapshot['hash'],
            'receipt_hash' => hash('sha256', $payment->receipt_hash.'|'.$snapshot['hash'].'|'.now()->toIso8601String()),
            'acknowledgment_ip' => $request->ip(),
            'acknowledgment_device' => $data['device'] ?? $request->userAgent(),
            'dispute_reason' => null,
            'disputed_at' => null,
        ]);

        $payment->loadMissing('employee.user', 'salaryPeriod');
        try {
            $notifications->create(
                AdminNotificationService::TYPE_SALARY_RECEIPT_RECEIVED,
                'تم تأكيد استلام راتب',
                ($payment->employee->user?->name ?? 'الموظف').' أكد استلام مبلغ '.number_format((float) $payment->amount_paid, 2).' شيكل.',
                ['salary_payment_item_id' => (string) $payment->id, 'receipt_status' => 'received'],
                $employee->id,
                'salary_payment_item',
                $payment->id,
                true
            );
        } catch (\Throwable $error) {
            Log::error('Admin salary receipt notification failed', [
                'salary_payment_item_id' => $payment->id,
                'error' => $error->getMessage(),
            ]);
        }

        return response()->json(['status' => 'success', 'message' => 'تم توثيق الاستلام والتوقيع بنجاح.', 'receipt' => $this->receiptPayload($payment->fresh())]);
    }

    public function dispute(Request $request, int $item, AdminNotificationService $notifications)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $employee = $request->user()->employee;
        abort_unless($employee, 403);
        $payment = SalaryPaymentItem::where('employee_id', $employee->id)->findOrFail($item);
        if ($payment->receipt_status === 'received') {
            throw ValidationException::withMessages(['receipt' => ['لا يمكن الاعتراض بعد توقيع الاستلام.']]);
        }
        $payment->update(['receipt_status' => 'disputed', 'dispute_reason' => $data['reason'], 'disputed_at' => now()]);
        $payment->loadMissing('employee.user');
        try {
            $notifications->create(
                AdminNotificationService::TYPE_SALARY_RECEIPT_DISPUTED,
                'اعتراض على دفعة راتب',
                ($payment->employee->user?->name ?? 'الموظف').' اعترض على دفعة الراتب: '.$data['reason'],
                ['salary_payment_item_id' => (string) $payment->id, 'receipt_status' => 'disputed'],
                $employee->id,
                'salary_payment_item',
                $payment->id,
                true
            );
        } catch (\Throwable $error) {
            Log::error('Admin salary dispute notification failed', [
                'salary_payment_item_id' => $payment->id,
                'error' => $error->getMessage(),
            ]);
        }

        return response()->json(['status' => 'success', 'message' => 'تم إرسال الاعتراض للإدارة.', 'receipt' => $payment]);
    }

    /** @return array<string, mixed> */
    private function receiptPayload(SalaryPaymentItem $payment): array
    {
        $payment->loadMissing(['employee.user:id,name', 'salaryPeriod', 'batch.creator:id,name', 'batch.box:id,name,currency']);
        return array_merge($payment->toArray(), [
            'company' => ['name' => 'Doctor Bike', 'name_ar' => 'دكتور بايك', 'seal_label' => 'توقيع وختم دكتور بايك'],
            'verification_code' => $payment->receipt_hash,
            'verification_text' => 'PAYROLL-'.$payment->id.'-'.substr((string) $payment->receipt_hash, 0, 12),
            'verification_url' => url('/api/payroll/verify/'.$payment->receipt_hash),
        ]);
    }
}
