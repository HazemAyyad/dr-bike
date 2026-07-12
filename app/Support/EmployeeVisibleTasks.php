<?php

namespace App\Support;

use App\Enums\EmployeeTaskStatus;
use App\Models\EmployeeDetail;
use App\Models\EmployeeSubTask;
use App\Models\EmployeeTask;
use App\Models\EmployeeTaskOccurrence;
use App\Services\EmployeeTasks\EmployeeTaskAssigneeService;
use App\Support\TaskProofMediaType;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Tasks visible to an employee in the app (legacy rows + v2 occurrences).
 */
class EmployeeVisibleTasks
{
    public const TIMEZONE = 'Asia/Hebron';
    public const ONE_TIME_PERSISTENT = 'oneTimePersistent';

    /** Match lazy occurrence generation in EmployeeTaskRecurrenceService. */
    public const OCCURRENCE_VISIBILITY_DAYS_FORWARD = 14;

    /** Allow overdue / previous week navigation in the employee app. */
    public const OCCURRENCE_VISIBILITY_DAYS_BACK = 7;

    public static function legacyForEmployee(int $employeeId): Collection
    {
        return EmployeeTask::query()
            ->where('is_canceled', 0)
            ->whereNull('occurrence_id')
            ->where(function ($query) use ($employeeId) {
                $query->where('employee_id', $employeeId);
                if (Schema::hasTable('employee_task_assignees')) {
                    $query->orWhereIn('id', function ($sub) use ($employeeId) {
                        $sub->select('employee_task_id')
                            ->from('employee_task_assignees')
                            ->where('employee_id', $employeeId);
                    });
                }
            })
            ->get()
            ->filter(fn (EmployeeTask $task) => self::isVisibleToEmployee($task))
            ->filter(fn (EmployeeTask $task) => self::includeLegacyRowInEmployeePayload($task))
            ->values();
    }

    /**
     * V1 recurrence pre-generates child rows; only send rows the employee actually interacted with.
     * Pending copies are rendered virtually from the parent in the app.
     */
    public static function includeLegacyRowInEmployeePayload(EmployeeTask $task): bool
    {
        if (empty($task->parent_id)) {
            return true;
        }

        $status = EmployeeTaskStatus::normalize($task->status)->value;

        if (in_array($status, ['in_progress', 'overdue', 'started'], true)) {
            return true;
        }

        if (in_array($status, ['completed', 'waiting_review'], true)) {
            return (int) ($task->completed_by_employee_id ?? 0) > 0
                || ! empty($task->submitted_at);
        }

        if ($status === 'pending' && self::legacyChildInVisibilityWindow($task)) {
            return true;
        }

        return false;
    }

    public static function legacyChildInVisibilityWindow(EmployeeTask $task): bool
    {
        if (empty($task->start_time)) {
            return false;
        }

        $day = Carbon::parse($task->start_time)->timezone(self::TIMEZONE)->startOfDay();
        $today = Carbon::now()->timezone(self::TIMEZONE)->startOfDay();
        $min = $today->copy()->subDays(self::OCCURRENCE_VISIBILITY_DAYS_BACK);
        $max = $today->copy()->addDays(self::OCCURRENCE_VISIBILITY_DAYS_FORWARD);

        return $day->betweenIncluded($min, $max);
    }

    public static function occurrencesForEmployee(int $employeeId): Collection
    {
        if (! Schema::hasTable('employee_task_occurrences')) {
            return collect();
        }

        return EmployeeTaskOccurrence::query()
            ->where('is_canceled', 0)
            ->where(function ($query) use ($employeeId) {
                $query->where('employee_id', $employeeId);
                if (Schema::hasTable('employee_task_assignees')) {
                    $query->orWhereIn('legacy_task_id', function ($sub) use ($employeeId) {
                        $sub->select('employee_task_id')
                            ->from('employee_task_assignees')
                            ->where('employee_id', $employeeId);
                    });
                }
            })
            ->get()
            ->filter(fn (EmployeeTaskOccurrence $task) => self::isOccurrenceVisibleToEmployee($task))
            ->values();
    }

    public static function dashboardPayload(int $employeeId): Collection
    {
        if (Schema::hasTable('employee_task_templates')) {
            app(\App\Services\EmployeeTasks\EmployeeTaskRecurrenceService::class)
                ->ensureActiveTemplateOccurrences($employeeId);
        }

        $legacyRows = self::legacyForEmployee($employeeId);
        $dayInstance = app(\App\Services\EmployeeTasks\EmployeeLegacyDayInstanceService::class);

        foreach ($legacyRows as $task) {
            if (empty($task->parent_id) && $dayInstance->isRecurringParent($task)) {
                $dayInstance->repairTemplateIfNeeded($task);
            }
        }

        $legacy = $legacyRows
            ->map(fn (EmployeeTask $task) => self::mapLegacyForDashboard($task->fresh(), $employeeId))
            ->toBase();

        $occurrences = self::occurrencesForEmployee($employeeId)
            ->filter(fn (EmployeeTaskOccurrence $task) => self::passesOccurrenceDayFilter($task))
            ->map(fn (EmployeeTaskOccurrence $task) => self::mapOccurrenceForDashboard($task, $employeeId))
            ->toBase();

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
        if (($task->template?->recurrence_type ?? null) === self::ONE_TIME_PERSISTENT) {
            return true;
        }

        $today = Carbon::now()->timezone(self::TIMEZONE)->startOfDay();
        $scheduled = Carbon::parse($task->scheduled_date ?? $task->start_time)
            ->timezone(self::TIMEZONE)
            ->startOfDay();

        $from = $today->copy()->subDays(self::OCCURRENCE_VISIBILITY_DAYS_BACK);
        $to = $today->copy()->addDays(self::OCCURRENCE_VISIBILITY_DAYS_FORWARD);

        return $scheduled->betweenIncluded($from, $to);
    }

    public static function todayDateString(): string
    {
        return Carbon::now()->timezone(self::TIMEZONE)->toDateString();
    }

    public static function localDateTimeString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)
            ->timezone(self::TIMEZONE)
            ->format('Y-m-d H:i:s');
    }

    /**
     * @return array<string, mixed>
     */
    public static function progressForTask(string $status, int $subCount, int $subDone): int
    {
        if ($subCount > 0) {
            if ($subDone >= $subCount) {
                return 100;
            }

            return (int) round(($subDone / $subCount) * 100);
        }

        return match (EmployeeTaskStatus::normalize($status)->value) {
            'completed' => 100,
            'waiting_review' => 90,
            'in_progress', 'started' => 50,
            default => 0,
        };
    }

    public static function mapLegacyForDashboard(EmployeeTask $task, int $viewerEmployeeId): array
    {
        $task->loadMissing(['completedByEmployee.user']);
        $base = EmployeeSubTask::query()->forLegacyTask($task);
        $subCount = (int) (clone $base)->count();
        $subDone = (int) (clone $base)->where('status', 'completed')->count();
        $completedByName = $task->completedByEmployee?->user?->name;
        $assigneeService = app(EmployeeTaskAssigneeService::class);

        return [
            'id' => $task->id,
            'task_id' => $task->id,
            'employee_id' => $task->employee_id,
            'assignee_ids' => $assigneeService->idsForTask($task),
            'completed_by_employee_id' => $task->completed_by_employee_id,
            'completed_by_name' => $completedByName,
            'can_execute' => self::canEmployeeExecuteTask($task, $viewerEmployeeId),
            'name' => $task->name,
            'start_time' => $task->start_time,
            'end_time' => $task->end_time,
            'status' => EmployeeTaskStatus::normalize($task->status)->value,
            'parent_id' => $task->parent_id,
            'task_recurrence' => $task->task_recurrence,
            'task_recurrence_time' => $task->task_recurrence_time,
            'is_forced_to_upload_img' => (bool) $task->is_forced_to_upload_img,
            'proof_media_type' => TaskProofMediaType::normalize($task->proof_media_type ?? null, (bool) $task->is_forced_to_upload_img),
            'occurrence_id' => $task->occurrence_id,
            'template_id' => $task->template_id,
            'source' => 'legacy',
            'has_sub_tasks' => $subCount > 0,
            'sub_tasks_count' => $subCount,
            'progress' => self::progressForTask($task->status, $subCount, $subDone),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function mapOccurrenceForDashboard(EmployeeTaskOccurrence $task, int $viewerEmployeeId): array
    {
        $task->loadMissing(['template', 'subtasks', 'employee.user']);
        $subCount = $task->subtasks->count();
        $subDone = $task->subtasks->where('status', 'completed')->count();
        $completedByName = null;
        if (Schema::hasColumn('employee_task_occurrences', 'completed_by_employee_id') && $task->completed_by_employee_id) {
            $completedBy = \App\Models\EmployeeDetail::with('user')->find($task->completed_by_employee_id);
            $completedByName = $completedBy?->user?->name;
        }

        $assigneeIds = [(int) $task->employee_id];
        if ($task->legacy_task_id) {
            $legacy = EmployeeTask::find($task->legacy_task_id);
            if ($legacy) {
                $assigneeIds = app(EmployeeTaskAssigneeService::class)->idsForTask($legacy);
            }
        }

        return [
            'id' => $task->id,
            'task_id' => $task->legacy_task_id ?? $task->id,
            'employee_id' => $task->employee_id,
            'assignee_ids' => $assigneeIds,
            'completed_by_employee_id' => $task->completed_by_employee_id ?? null,
            'completed_by_name' => $completedByName,
            'can_execute' => self::canEmployeeExecuteOccurrence($task, $viewerEmployeeId),
            'name' => $task->name,
            'start_time' => self::localDateTimeString($task->start_time),
            'end_time' => self::localDateTimeString($task->end_time),
            'scheduled_date' => $task->scheduled_date
                ? Carbon::parse($task->scheduled_date)->toDateString()
                : null,
            'status' => EmployeeTaskStatus::normalize($task->status)->value,
            'task_recurrence' => $task->template?->recurrence_type ?? 'noRepeat',
            'task_recurrence_time' => [],
            'is_forced_to_upload_img' => (bool) $task->is_forced_to_upload_img,
            'proof_media_type' => TaskProofMediaType::normalize($task->proof_media_type ?? null, (bool) $task->is_forced_to_upload_img),
            'occurrence_id' => $task->id,
            'template_id' => $task->template_id,
            'source' => 'occurrence',
            'has_sub_tasks' => $subCount > 0,
            'sub_tasks_count' => $subCount,
            'progress' => self::progressForTask($task->status, $subCount, $subDone),
        ];
    }

    /**
     * @return array{total: int, completed: int, progress_percent: int}
     */
    public static function todaySummaryForEmployee(int $employeeId): array
    {
        $today = Carbon::now()->timezone(self::TIMEZONE)->startOfDay();
        $employee = EmployeeDetail::find($employeeId);
        $tasks = self::dashboardPayload($employeeId)->filter(function ($row) use ($today, $employee) {
            return self::taskAppliesOnDate($row, $today, $employee);
        });

        $total = $tasks->count();
        if ($total === 0) {
            return ['total' => 0, 'completed' => 0, 'progress_percent' => 0];
        }

        $completed = $tasks->filter(fn ($row) => in_array($row['status'], ['completed', 'waiting_review'], true))->count();
        $progressSum = $tasks->sum(fn ($row) => self::effectiveProgressForDate($row, $today));

        return [
            'total' => $total,
            'completed' => $completed,
            'progress_percent' => (int) round($progressSum / $total),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function taskAppliesOnDate(array $row, Carbon $date, ?EmployeeDetail $employee = null): bool
    {
        $start = $row['start_time'] ?? null;
        if (empty($start)) {
            return false;
        }

        $startCarbon = Carbon::parse($start)->timezone(self::TIMEZONE)->startOfDay();
        $check = $date->copy()->timezone(self::TIMEZONE)->startOfDay();

        $recurrence = $row['task_recurrence'] ?? 'noRepeat';

        if ($recurrence === self::ONE_TIME_PERSISTENT) {
            return in_array($row['status'] ?? null, [
                'ongoing',
                'pending',
                'in_progress',
                'waiting_review',
                'overdue',
            ], true);
        }

        if ($recurrence === 'daily' && ! EmployeeWorkingDays::isWorkingDay($employee, $check)) {
            return false;
        }

        if ($check->toDateString() === $startCarbon->toDateString()) {
            return true;
        }

        if (! empty($row['parent_id'])) {
            return false;
        }

        if ($recurrence === 'noRepeat' || $recurrence === null || $recurrence === '') {
            return false;
        }

        if ($check->lt($startCarbon)) {
            return false;
        }

        $end = $row['end_time'] ?? null;
        if (! empty($end)) {
            $endCarbon = Carbon::parse($end)->timezone(self::TIMEZONE)->startOfDay();
            if ($check->gt($endCarbon)) {
                return false;
            }
        }

        $times = is_array($row['task_recurrence_time'] ?? null)
            ? $row['task_recurrence_time']
            : [];

        return match ($recurrence) {
            'daily' => true,
            'weekly' => in_array(strtolower($check->format('l')), array_map('strtolower', $times), true),
            'monthly' => in_array((string) $check->format('j'), $times, true),
            default => false,
        };
    }

    /**
     * Recurring parent templates carry anchor-day subtask proof; other calendar days start at 0%.
     *
     * @param  array<string, mixed>  $row
     */
    public static function effectiveProgressForDate(array $row, Carbon $date): int
    {
        $progress = (int) ($row['progress'] ?? 0);
        $recurrence = $row['task_recurrence'] ?? 'noRepeat';

        if (! empty($row['parent_id']) || $recurrence === 'noRepeat' || $recurrence === '') {
            return $progress;
        }

        $start = $row['start_time'] ?? null;
        if (empty($start)) {
            return $progress;
        }

        $anchor = Carbon::parse($start)->timezone(self::TIMEZONE)->startOfDay();
        $check = $date->copy()->timezone(self::TIMEZONE)->startOfDay();

        if ($check->toDateString() === $anchor->toDateString()) {
            return $progress;
        }

        return 0;
    }

    public static function canEmployeeExecuteTask(EmployeeTask $task, int $viewerEmployeeId): bool
    {
        $assigneeService = app(EmployeeTaskAssigneeService::class);
        if (! $assigneeService->isAssignee($task, $viewerEmployeeId)) {
            return false;
        }

        $status = EmployeeTaskStatus::normalize($task->status);
        if (in_array($status, [EmployeeTaskStatus::Canceled], true)) {
            return false;
        }

        $completedBy = (int) ($task->completed_by_employee_id ?? 0);
        if ($completedBy > 0 && $completedBy !== $viewerEmployeeId) {
            return false;
        }

        if (in_array($status, [EmployeeTaskStatus::Completed, EmployeeTaskStatus::WaitingReview], true)
            && $completedBy > 0
            && $completedBy !== $viewerEmployeeId) {
            return false;
        }

        return ! in_array($status, [EmployeeTaskStatus::Completed], true)
            || $completedBy === $viewerEmployeeId
            || $completedBy === 0;
    }

    public static function canEmployeeExecuteOccurrence(EmployeeTaskOccurrence $task, int $viewerEmployeeId): bool
    {
        $isPrimary = (int) $task->employee_id === $viewerEmployeeId;
        $isCoAssignee = false;

        if (! $isPrimary && $task->legacy_task_id) {
            $legacy = EmployeeTask::find($task->legacy_task_id);
            if ($legacy) {
                $isCoAssignee = app(EmployeeTaskAssigneeService::class)->isAssignee($legacy, $viewerEmployeeId);
            }
        }

        if (! $isPrimary && ! $isCoAssignee) {
            return false;
        }

        $status = EmployeeTaskStatus::normalize($task->status);
        $completedBy = (int) ($task->completed_by_employee_id ?? 0);

        if ($completedBy > 0 && $completedBy !== $viewerEmployeeId) {
            return false;
        }

        return ! in_array($status, [EmployeeTaskStatus::Completed], true);
    }
}
