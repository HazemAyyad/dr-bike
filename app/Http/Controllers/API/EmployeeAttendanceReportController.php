<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\EmployeeDetail;
use App\Services\AttendanceSalaryService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeeAttendanceReportController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'report_type' => ['required', Rule::in(['daily', 'weekly', 'monthly'])],
            'month' => ['required', 'integer', 'between:1,12'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'day' => ['nullable', 'integer', 'between:1,31'],
            'week' => ['nullable', 'integer', 'min:1', 'max:6'],
            'employee_ids' => ['nullable', 'array'],
            'employee_ids.*' => ['integer'],
        ]);

        $reportType = $validated['report_type'];
        $month = (int) $validated['month'];
        $year = (int) $validated['year'];

        if ($reportType === 'daily' && empty($validated['day'])) {
            throw ValidationException::withMessages([
                'day' => __('The day field is required when report type is daily.'),
            ]);
        }
        if ($reportType === 'weekly' && empty($validated['week'])) {
            throw ValidationException::withMessages([
                'week' => __('The week field is required when report type is weekly.'),
            ]);
        }

        [$periodStart, $periodEnd] = $this->resolveReportPeriod($reportType, $year, $month, $validated['day'] ?? null, $validated['week'] ?? null);

        $employeeIds = $this->normalizeEmployeeIdsFromRequest($request);
        /** @phpstan-ignore-next-line */
        $employees = EmployeeDetail::query()
            ->with('user')
            ->when(
                ($employeeIds !== null && count($employeeIds) > 0),
                fn ($q) => $q->whereIn('id', $employeeIds),
            )
            ->orderBy('id')
            ->get();

        /** @var AttendanceSalaryService $salaryService */
        $salaryService = app(AttendanceSalaryService::class);
        $ids = $employees->pluck('id')->map(fn ($id) => (int) $id)->all();

        $workedMap = $salaryService->sumWorkedMinutesForEmployeesBetween($ids, $periodStart, $periodEnd);

        $rows = [];
        foreach ($employees as $employee) {
            $worked = (int) ($workedMap[$employee->id] ?? 0);
            $rows[] = $salaryService->buildAttendanceReportRow(
                $employee,
                $periodStart,
                $periodEnd,
                $worked,
                $month,
                $year
            );
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'report_type' => $reportType,
                'month' => $month,
                'year' => $year,
                'day' => $validated['day'] ?? null,
                'week' => $validated['week'] ?? null,
                'period_from' => $periodStart->toDateString(),
                'period_to' => $periodEnd->toDateString(),
                'employees' => $rows,
            ],
        ]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveReportPeriod(string $reportType, int $year, int $month, ?int $day, ?int $week): array
    {
        $monthStart = Carbon::create($year, $month, 1)->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth()->startOfDay();

        if ($reportType === 'monthly') {
            return [$monthStart->copy(), $monthEnd->copy()];
        }

        if ($reportType === 'daily') {
            $d = (int) $day;
            if (! checkdate($month, $d, $year)) {
                throw ValidationException::withMessages([
                    'day' => __('Invalid date for the selected month and year.'),
                ]);
            }
            $one = Carbon::create($year, $month, $d)->startOfDay();

            return [$one->copy(), $one->copy()];
        }

        // weekly (1-based block inside the calendar month)
        $w = (int) $week;
        $weekStart = $monthStart->copy()->addDays(($w - 1) * 7);
        if ($weekStart->month !== (int) $month || $weekStart->year !== (int) $year) {
            throw ValidationException::withMessages([
                'week' => __('The selected week does not fall in the given month.'),
            ]);
        }
        $weekEnd = $weekStart->copy()->addDays(6);
        if ($weekEnd->gt($monthEnd)) {
            $weekEnd = $monthEnd->copy();
        }

        return [$weekStart->copy()->startOfDay(), $weekEnd->copy()->startOfDay()];
    }

    /**
     * @return array<int>|null null أو بعد التصفية فارغة ⇒ كل الموظفين (لا يُستعمل whereIn فارغ)
     */
    private function normalizeEmployeeIdsFromRequest(Request $request): ?array
    {
        $raw = $request->input('employee_ids');

        if ($raw === null || $raw === '') {
            return null;
        }

        $ids = [];
        if (is_string($raw)) {
            $parts = array_filter(array_map('trim', explode(',', $raw)), fn ($s) => $s !== '');
            foreach ($parts as $p) {
                if (is_numeric($p)) {
                    $ids[] = (int) $p;
                }
            }
        } elseif (is_array($raw)) {
            foreach ($raw as $v) {
                if (is_numeric($v)) {
                    $ids[] = (int) $v;
                }
            }
        } else {
            return null;
        }

        $ids = array_values(array_unique(array_filter($ids, fn ($id) => $id > 0)));

        if ($ids === []) {
            return null;
        }

        return $ids;
    }
}
