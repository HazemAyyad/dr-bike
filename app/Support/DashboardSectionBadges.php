<?php

namespace App\Support;

use App\Models\EmployeeDetail;
use App\Models\EmployeeSuggestion;
use App\Models\Followup;
use App\Models\IncomingCheck;
use App\Models\Maintenance;
use App\Models\OutgoingCheck;
use App\Models\SpecialTask;
use App\Models\SupportConversation;
use App\Models\SuspendedInstantSale;
use App\Models\User;
use App\Services\AttendanceSalaryService;
use App\Services\EmployeeTasks\EmployeeTaskListService;
use Carbon\Carbon;

class DashboardSectionBadges
{
    public const SUPPORT_PERMISSION = 'Technical Support';

    /**
     * @return array<string, int>
     */
    public static function forUser(User $user): array
    {
        $employeeId = (int) ($user->employee?->id ?? 0);
        $canManageSupport = self::canManageSupport($user);

        $supportQuery = SupportConversation::query()
            ->where('status', '!=', SupportConversation::STATUS_CLOSED);
        if (! $canManageSupport && $employeeId > 0) {
            $supportQuery->where('employee_id', $employeeId);
        }

        $suggestionsQuery = EmployeeSuggestion::query()
            ->where('status', '!=', EmployeeSuggestion::STATUS_CLOSED);
        if ($user->type !== 'admin' && $employeeId > 0) {
            $suggestionsQuery->where('employee_id', $employeeId);
        }

        $salesQuery = SuspendedInstantSale::query()
            ->where('status', SuspendedInstantSale::STATUS_SUSPENDED);
        if ($user->type !== 'admin') {
            $salesQuery->where('created_by_user_id', $user->id);
        }

        return [
            'technical_support' => (int) $supportQuery->count(),
            'employee_tasks_today_pending' => self::employeeTasksTodayPending($user),
            'special_tasks_today_pending' => self::specialTasksTodayPending(),
            'employees_absent_today' => self::employeesAbsentToday(),
            'maintenance' => (int) Maintenance::query()->where('status', '!=', 'delivered')->count(),
            'follow_up' => (int) Followup::query()
                ->where('status', 'ongoing')
                ->where(function ($query) {
                    $query->whereNull('is_canceled')->orWhere('is_canceled', 0);
                })
                ->count(),
            'sales' => (int) $salesQuery->count(),
            'suggestions' => (int) $suggestionsQuery->count(),
            'checks_incoming_red' => self::urgentChecksCount(IncomingCheck::class, 'red'),
            'checks_incoming_yellow' => self::urgentChecksCount(IncomingCheck::class, 'yellow'),
            'checks_outgoing_red' => self::urgentChecksCount(OutgoingCheck::class, 'red', ['not_cashed', 'cashed_to_person']),
            'checks_outgoing_yellow' => self::urgentChecksCount(OutgoingCheck::class, 'yellow', ['not_cashed', 'cashed_to_person']),
        ];
    }

    private static function employeesAbsentToday(): int
    {
        $today = EmployeeAttendanceToday::todayDateString();
        $todayName = strtolower(Carbon::parse($today, EmployeeAttendanceToday::TIMEZONE)->format('l'));
        $salaryService = app(AttendanceSalaryService::class);

        return EmployeeDetail::query()
            ->get(['id', 'weekly_days_off'])
            ->filter(function (EmployeeDetail $employee) use ($salaryService, $todayName) {
                $weeklyDaysOff = $salaryService->effectiveWeeklyDaysOff($employee);

                return ! in_array($todayName, $weeklyDaysOff, true)
                    && ! EmployeeAttendanceToday::hasCheckedInToday((int) $employee->id);
            })
            ->count();
    }

    private static function employeeTasksTodayPending(User $user): int
    {
        $employeeId = (int) ($user->employee?->id ?? 0);

        if ($user->type !== 'admin') {
            if ($employeeId <= 0) {
                return 0;
            }

            return self::ongoingEmployeeTaskItemsForToday()
                ->filter(fn ($item) => (int) ($item['employee_id'] ?? 0) === $employeeId)
                ->count();
        }

        return self::ongoingEmployeeTaskItemsForToday()->count();
    }

    private static function specialTasksTodayPending(): int
    {
        return (int) SpecialTask::query()
            ->where('is_canceled', 0)
            ->where('status', '!=', 'completed')
            ->whereDate('start_date', EmployeePendingTasksForToday::todayDateString())
            ->count();
    }

    private static function ongoingEmployeeTaskItemsForToday()
    {
        $today = EmployeePendingTasksForToday::todayDateString();

        return app(EmployeeTaskListService::class)
            ->getOngoingItems(fn ($employee) => '')
            ->filter(function ($item) use ($today) {
                $start = $item['start_time'] ?? null;
                if (! $start) {
                    return false;
                }

                return Carbon::parse($start)
                    ->timezone(EmployeePendingTasksForToday::TIMEZONE)
                    ->toDateString() === $today;
            })
            ->unique(fn ($item) => (string) ($item['task_id'] ?? '').':'.(string) ($item['employee_id'] ?? ''))
            ->values();
    }

    /**
     * @param class-string<IncomingCheck|OutgoingCheck> $model
     */
    private static function urgentChecksCount(string $model, string $level, array $statuses = ['not_cashed']): int
    {
        $today = Carbon::now(EmployeeAttendanceToday::TIMEZONE)->startOfDay();
        $redEnd = $today->copy()->addDay();
        $yellowEnd = $today->copy()->addDays(6);

        $query = $model::query()
            ->whereIn('status', $statuses)
            ->whereNotNull('due_date');

        if ($level === 'red') {
            $query->whereDate('due_date', '<=', $redEnd->toDateString());
        } else {
            $query->whereDate('due_date', '>', $redEnd->toDateString())
                ->whereDate('due_date', '<=', $yellowEnd->toDateString());
        }

        return (int) $query->count();
    }

    private static function canManageSupport(User $user): bool
    {
        if ($user->type === 'admin') {
            return true;
        }

        return (bool) $user->employee?->permissions()
            ->whereHas('permission', fn ($query) => $query->where('name_en', self::SUPPORT_PERMISSION))
            ->exists();
    }
}
