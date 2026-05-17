<?php

namespace App\Support;

use App\Models\EmployeeTask;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class EmployeePendingTasksForToday
{
    /**
     * Tasks assigned to the employee that are not completed/cancelled and apply to today
     * (recurrence rules aligned with EmployeeTasks::getTasks intent, using "today" for day checks).
     */
    public static function forEmployee(int $employeeId): Collection
    {
        $tasks = EmployeeTask::query()
            ->where('employee_id', $employeeId)
            ->where('is_canceled', 0)
            ->where('status', '!=', 'completed')
            ->get();

        return $tasks->filter(fn (EmployeeTask $task) => self::appliesToday($task))->values();
    }

    /** مهام اليوم التي يراها الموظف في التطبيق (نفس منطق EmployeeData). */
    public static function visibleForEmployee(int $employeeId): Collection
    {
        return self::forEmployee($employeeId)
            ->filter(fn (EmployeeTask $task) => self::isVisibleToEmployee($task))
            ->values();
    }

    public static function isVisibleToEmployee(EmployeeTask $task): bool
    {
        if (! (bool) $task->not_shown_for_employee) {
            return true;
        }

        if (empty($task->start_time)) {
            return false;
        }

        return Carbon::parse($task->start_time)->startOfDay()->lte(now()->startOfDay());
    }

    public static function appliesToday(EmployeeTask $task): bool
    {
        $today = Carbon::now();
        $times = is_array($task->task_recurrence_time) ? $task->task_recurrence_time : [];
        $dayName = strtolower($today->format('l'));
        $dayOfMonth = (string) (int) $today->format('d');

        $recurrence = $task->task_recurrence ?? 'noRepeat';

        return match ($recurrence) {
            'noRepeat', '', null => true,
            'daily' => true,
            'weekly' => in_array($dayName, $times, true),
            'monthly' => in_array($dayOfMonth, array_map('strval', $times), true)
                || in_array((string) (int) $dayOfMonth, $times, true),
            default => false,
        };
    }
}
