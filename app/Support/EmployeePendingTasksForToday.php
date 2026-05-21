<?php

namespace App\Support;

use App\Enums\EmployeeTaskStatus;
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
        return self::pendingActionForEmployee($employeeId);
    }

    /**
     * All visible tasks for today (any status) — for completion checks.
     */
    public static function allVisibleForEmployeeToday(int $employeeId): Collection
    {
        $today = self::todayDateString();

        $legacy = EmployeeTask::query()
            ->where('employee_id', $employeeId)
            ->where('is_canceled', 0)
            ->whereDate('start_time', $today)
            ->get()
            ->filter(fn (EmployeeTask $task) => self::isVisibleToEmployee($task));

        if (! Schema::hasTable('employee_task_occurrences')) {
            return $legacy->values();
        }

        $occurrences = EmployeeTaskOccurrence::query()
            ->where('employee_id', $employeeId)
            ->where('is_canceled', 0)
            ->whereDate('scheduled_date', $today)
            ->get()
            ->filter(fn (EmployeeTaskOccurrence $task) => EmployeeVisibleTasks::isOccurrenceVisibleToEmployee($task));

        return $legacy->merge($occurrences)->values();
    }

    /** Tasks still needing employee action today (not completed / not awaiting admin only). */
    public static function pendingActionForEmployee(int $employeeId): Collection
    {
        return self::allVisibleForEmployeeToday($employeeId)
            ->filter(fn ($task) => ! self::isEmployeeFinishedStatus(self::normalizeStatus($task)))
            ->values();
    }

    /** True when employee has visible tasks today and all are done or waiting review. */
    public static function allVisibleTodayFinishedByEmployee(int $employeeId): bool
    {
        $all = self::allVisibleForEmployeeToday($employeeId);
        if ($all->isEmpty()) {
            return false;
        }

        return $all->every(fn ($task) => self::isEmployeeFinishedStatus(self::normalizeStatus($task)));
    }

    public static function isEmployeeFinishedStatus(string $status): bool
    {
        return in_array($status, [
            EmployeeTaskStatus::Completed->value,
            EmployeeTaskStatus::WaitingReview->value,
        ], true);
    }

    public static function normalizeStatus(EmployeeTask|EmployeeTaskOccurrence $task): string
    {
        return EmployeeTaskStatus::normalize($task->status)->value;
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
