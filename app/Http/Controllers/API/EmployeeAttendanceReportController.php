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
            'report_type' => ['required', Rule::in(['daily', 'weekly', 'monthly', 'custom'])],
            'month' => ['required', 'integer', 'between:1,12'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'day' => ['nullable', 'integer', 'between:1,31'],
            'week' => ['nullable', 'integer', 'min:1', 'max:6'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
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
        if ($reportType === 'custom' && (empty($validated['date_from']) || empty($validated['date_to']))) {
            throw ValidationException::withMessages([
                'date_from' => __('The date from and date to fields are required when report type is custom.'),
            ]);
        }

        [$periodStart, $periodEnd] = $this->resolveReportPeriod(
            $reportType,
            $year,
            $month,
            $validated['day'] ?? null,
            $validated['week'] ?? null,
            $validated['date_from'] ?? null,
            $validated['date_to'] ?? null,
        );

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
                'date_from' => $reportType === 'custom' ? $periodStart->toDateString() : null,
                'date_to' => $reportType === 'custom' ? $periodEnd->toDateString() : null,
                'employees' => $rows,
            ],
        ]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveReportPeriod(
        string $reportType,
        int $year,
        int $month,
        ?int $day,
        ?int $week,
        ?string $dateFrom = null,
        ?string $dateTo = null,
    ): array {
        $monthStart = Carbon::create($year, $month, 1)->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth()->startOfDay();

        if ($reportType === 'custom') {
            $from = Carbon::parse((string) $dateFrom)->startOfDay();
            $to = Carbon::parse((string) $dateTo)->startOfDay();
            if ($to->lt($from)) {
                throw ValidationException::withMessages([
                    'date_to' => __('The date to must be after or equal to date from.'),
                ]);
            }

            return [$from->copy(), $to->copy()];
        }

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
