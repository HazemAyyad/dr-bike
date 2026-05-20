<?php

namespace App\Support;

use App\Enums\EmployeeTaskStatus;
use App\Models\EmployeeTask;
use App\Models\EmployeeTaskOccurrence;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Tasks visible to an employee in the app (legacy rows + v2 occurrences).
 */
class EmployeeVisibleTasks
{
    public const TIMEZONE = 'Asia/Hebron';

    public static function legacyForEmployee(int $employeeId): Collection
    {
        return EmployeeTask::query()
            ->where('employee_id', $employeeId)
            ->where('is_canceled', 0)
            ->whereNull('occurrence_id')
            ->get()
            ->filter(fn (EmployeeTask $task) => self::isVisibleToEmployee($task))
            ->values();
    }

    public static function occurrencesForEmployee(int $employeeId): Collection
    {
        if (! Schema::hasTable('employee_task_occurrences')) {
            return collect();
        }

        return EmployeeTaskOccurrence::query()
            ->where('employee_id', $employeeId)
            ->where('is_canceled', 0)
            ->get()
            ->filter(fn (EmployeeTaskOccurrence $task) => self::isOccurrenceVisibleToEmployee($task))
            ->values();
    }

    public static function dashboardPayload(int $employeeId): Collection
    {
        $legacy = self::legacyForEmployee($employeeId)
            ->filter(fn (EmployeeTask $task) => self::passesRecurrenceFilter($task))
            ->map(fn (EmployeeTask $task) => self::mapLegacyForDashboard($task));

        $occurrences = self::occurrencesForEmployee($employeeId)
            ->filter(fn (EmployeeTaskOccurrence $task) => self::passesOccurrenceDayFilter($task))
            ->map(fn (EmployeeTaskOccurrence $task) => self::mapOccurrenceForDashboard($task));

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

    public static function isOccurrenceVisibleToEmployee(EmployeeTaskOccurrence $task): bool
    {
        if (! (bool) $task->not_shown_for_employee) {
            return true;
        }

        $start = $task->start_time ?? $task->scheduled_date;
        if (empty($start)) {
            return false;
        }

        $today = Carbon::now()->timezone(self::TIMEZONE)->startOfDay();

        return Carbon::parse($start)
            ->timezone(self::TIMEZONE)
            ->startOfDay()
            ->lte($today);
    }

    public static function passesRecurrenceFilter(EmployeeTask $task): bool
    {
        $recurrence = $task->task_recurrence;
        $times = is_array($task->task_recurrence_time) ? $task->task_recurrence_time : [];
        $dayName = strtolower(Carbon::parse($task->start_time)->format('l'));
        $dayOfMonth = (int) Carbon::parse($task->start_time)->format('d');

        return match ($recurrence) {
            'noRepeat', 'daily' => true,
            'weekly' => in_array($dayName, $times),
            'monthly' => in_array((string) $dayOfMonth, $times),
            default => true,
        };
    }

    public static function passesOccurrenceDayFilter(EmployeeTaskOccurrence $task): bool
    {
        $today = self::todayDateString();
        $scheduled = $task->scheduled_date
            ? Carbon::parse($task->scheduled_date)->toDateString()
            : Carbon::parse($task->start_time)->toDateString();

        if ($scheduled === $today) {
            return true;
        }

        $task->loadMissing('template');
        $type = $task->template?->recurrence_type ?? 'noRepeat';

        return match ($type) {
            'daily' => true,
            'weekly' => strtolower(Carbon::parse($task->start_time)->format('l'))
                === strtolower(now()->timezone(self::TIMEZONE)->format('l')),
            'monthly' => (int) Carbon::parse($task->start_time)->format('d')
                === (int) now()->timezone(self::TIMEZONE)->format('d'),
            default => $scheduled === $today,
        };
    }

    public static function todayDateString(): string
    {
        return Carbon::now()->timezone(self::TIMEZONE)->toDateString();
    }

    /**
     * @return array<string, mixed>
     */
    public static function mapLegacyForDashboard(EmployeeTask $task): array
    {
        $subCount = $task->relationLoaded('subtasks')
            ? $task->subtasks->count()
            : $task->subtasks()->count();

        return [
            'id' => $task->id,
            'employee_id' => $task->employee_id,
            'name' => $task->name,
            'start_time' => $task->start_time,
            'end_time' => $task->end_time,
            'status' => EmployeeTaskStatus::normalize($task->status)->value,
            'task_recurrence' => $task->task_recurrence,
            'task_recurrence_time' => $task->task_recurrence_time,
            'is_forced_to_upload_img' => (bool) $task->is_forced_to_upload_img,
            'occurrence_id' => $task->occurrence_id,
            'source' => 'legacy',
            'has_sub_tasks' => $subCount > 0,
            'sub_tasks_count' => $subCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function mapOccurrenceForDashboard(EmployeeTaskOccurrence $task): array
    {
        $task->loadMissing(['template', 'subtasks']);
        $subCount = $task->subtasks->count();

        return [
            'id' => $task->id,
            'employee_id' => $task->employee_id,
            'name' => $task->name,
            'start_time' => $task->start_time,
            'end_time' => $task->end_time,
            'status' => EmployeeTaskStatus::normalize($task->status)->value,
            'task_recurrence' => $task->template?->recurrence_type ?? 'noRepeat',
            'task_recurrence_time' => [],
            'is_forced_to_upload_img' => (bool) $task->is_forced_to_upload_img,
            'occurrence_id' => $task->id,
            'source' => 'occurrence',
            'has_sub_tasks' => $subCount > 0,
            'sub_tasks_count' => $subCount,
        ];
    }
}
