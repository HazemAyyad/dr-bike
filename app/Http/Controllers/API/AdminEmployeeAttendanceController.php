<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\EmployeeDetail;
use App\Services\AttendanceSalaryService;
use App\Services\EmployeeAttendanceDayEditService;
use App\Services\EmployeeAttendanceOvertimeService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminEmployeeAttendanceController extends Controller
{
    public function manualCheckout(Request $request, int $employeeId)
    {
        try {
            $request->validate([
                'checkout_at' => 'nullable|date',
                'work_date' => 'nullable|date_format:Y-m-d',
            ]);

            $employee = EmployeeDetail::query()->findOrFail($employeeId);

            $checkoutAt = $request->filled('checkout_at')
                ? Carbon::parse($request->input('checkout_at'))
                : now();

            $workDate = $request->input('work_date') ?? $checkoutAt->toDateString();

            $result = app(\App\Services\EmployeeAttendanceCheckoutService::class)->checkout(
                $employee,
                $checkoutAt,
                $workDate,
                'manual'
            );

            $attendance = $result['attendance'];
            $salaryService = app(AttendanceSalaryService::class);
            $salary = $salaryService->calculateSalary(
                $employee,
                (int) ($attendance->normal_minutes ?? 0),
                (int) ($attendance->overtime_minutes ?? 0)
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.manual_checkout_success'),
                'scan' => 'out',
                'source' => 'manual',
                'segment_minutes' => $result['segment_minutes'],
                'day_worked_minutes' => $result['day_worked_minutes'],
                'checkout_at' => $checkoutAt->toIso8601String(),
                'worked_hours' => $salaryService->formatHours((int) ($attendance->worked_minutes ?? 0)),
                'normal_salary' => number_format((float) $salary['normal_salary'], 2, '.', ''),
                'overtime_salary' => number_format((float) $salary['overtime_salary'], 2, '.', ''),
                'total_salary' => number_format((float) $salary['total_salary'], 2, '.', ''),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response(['status' => 'error', 'message' => __('messages.employee_not_found')], 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
                'error' => $e->getMessage(),
            ], 200);
        }
    }

    public function updateDay(Request $request, int $employeeId)
    {
        try {
            $data = $request->validate([
                'work_date' => ['required', 'date_format:Y-m-d'],
                'check_in_at' => ['required', 'date'],
                'check_out_at' => ['nullable', 'date'],
            ]);

            $employee = EmployeeDetail::query()->findOrFail($employeeId);
            $workDate = (string) $data['work_date'];
            $checkInAt = Carbon::parse($data['check_in_at']);
            $checkOutAt = isset($data['check_out_at']) && $data['check_out_at'] !== null
                ? Carbon::parse($data['check_out_at'])
                : null;

            $result = app(EmployeeAttendanceDayEditService::class)->updateDayTimes(
                $employee,
                $workDate,
                $checkInAt,
                $checkOutAt,
                (int) ($request->user()?->id ?? 0)
            );

            $attendance = $result['attendance'];
            $salaryService = app(AttendanceSalaryService::class);
            $salary = $salaryService->calculateSalary(
                $employee,
                (int) ($attendance->normal_minutes ?? 0),
                (int) ($attendance->overtime_minutes ?? 0)
            );
            $overtimeService = app(EmployeeAttendanceOvertimeService::class);
            $overtimeRequest = $attendance->id
                ? $overtimeService->findForAttendanceDay((int) $attendance->id)
                : null;

            return response()->json([
                'status' => 'success',
                'message' => __('messages.attendance_day_updated'),
                'attendance' => [
                    'id' => (int) $attendance->id,
                    'date' => $attendance->date?->toDateString() ?? $workDate,
                    'arrived_at' => $attendance->arrived_at,
                    'left_at' => $attendance->left_at,
                    'worked_minutes' => (int) ($attendance->worked_minutes ?? 0),
                    'overtime_minutes' => (int) ($attendance->overtime_minutes ?? 0),
                ],
                'overtime_request' => $overtimeRequest
                    ? $overtimeService->toApiArray($overtimeRequest)
                    : null,
                'worked_hours' => $salaryService->formatHours((int) ($attendance->worked_minutes ?? 0)),
                'overtime_hours' => $salaryService->formatHours((int) ($attendance->overtime_minutes ?? 0)),
                'total_salary' => number_format((float) $salary['total_salary'], 2, '.', ''),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response(['status' => 'error', 'message' => __('messages.employee_not_found')], 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
                'error' => $e->getMessage(),
            ], 200);
        }
    }
}
