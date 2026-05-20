<?php

namespace App\Support;

use App\Models\EmployeeTask;
use App\Models\EmployeeTaskOccurrence;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class EmployeePendingTasksForToday
{
    public const TIMEZONE = 'Asia/Hebron';

    /**
     * مهام اليوم غير المنجزة: start_time في تاريخ اليوم (كل نسخة متكررة لها صف وتاريخها).
     */
    public static function forEmployee(int $employeeId): Collection
    {
        $tasks = EmployeeTask::query()
            ->where('employee_id', $employeeId)
            ->where('is_canceled', 0)
            ->where('status', '!=', 'completed')
            ->whereDate('start_time', self::todayDateString())
            ->get();

        return $tasks->values();
    }

    public static function todayDateString(): string
    {
        return Carbon::now()->timezone(self::TIMEZONE)->toDateString();
    }

    /** مهام اليوم التي يراها الموظف في التطبيق (نفس منطق EmployeeData). */
    public static function visibleForEmployee(int $employeeId): Collection
    {
        $legacy = self::forEmployee($employeeId)
            ->filter(fn (EmployeeTask $task) => self::isVisibleToEmployee($task));

        if (! Schema::hasTable('employee_task_occurrences')) {
            return $legacy->values();
        }

        $occurrences = EmployeeTaskOccurrence::query()
            ->where('employee_id', $employeeId)
            ->where('is_canceled', 0)
            ->where('status', '!=', 'completed')
            ->whereDate('scheduled_date', self::todayDateString())
            ->get()
            ->filter(fn (EmployeeTaskOccurrence $task) => EmployeeVisibleTasks::isOccurrenceVisibleToEmployee($task));

        return $legacy->merge($occurrences)->values();
    }

    public static function isVisibleToEmployee(EmployeeTask $task): bool
    {
        if (! (bool) $task->not_shown_for_employee) {
            return true;
        }

        if (empty($task->start_time)) {
            return false;
        }

        $today = Carbon::now()->timezone(self::TIMEZONE)->startOfDay();

        return Carbon::parse($task->start_time)
            ->timezone(self::TIMEZONE)
            ->startOfDay()
            ->lte($today);
    }
}
