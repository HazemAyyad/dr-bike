<?php

namespace App\Services\EmployeeTasks;

use App\Enums\EmployeeTaskStatus;
use App\Models\EmployeeSubTask;
use App\Models\EmployeeTask;
use App\Support\EmployeeVisibleTasks;
use Carbon\Carbon;

/**
 * Resolve V1 legacy recurring tasks to the row for a specific calendar day.
 */
class EmployeeLegacyDayInstanceService
{
    public function isRecurringParent(EmployeeTask $task): bool
    {
        if (! empty($task->parent_id)) {
            return false;
        }

        $recurrence = $task->task_recurrence ?? 'noRepeat';

        return $recurrence !== 'noRepeat' && $recurrence !== null && $recurrence !== '';
    }

    public function resolveForDate(EmployeeTask $task, ?Carbon $date = null): EmployeeTask
    {
        if (! empty($task->parent_id)) {
            return $task->fresh();
        }

        if (! $this->isRecurringParent($task)) {
            return $task->fresh();
        }

        $parent = $task->fresh(['subTasks']);
        $date = ($date ?? Carbon::parse($parent->start_time))
            ->timezone(EmployeeVisibleTasks::TIMEZONE)
            ->startOfDay();

        $anchor = Carbon::parse($parent->start_time)
            ->timezone(EmployeeVisibleTasks::TIMEZONE)
            ->startOfDay();

        if ($date->toDateString() === $anchor->toDateString()) {
            return $parent;
        }

        $existing = EmployeeTask::query()
            ->where('parent_id', $parent->id)
            ->where('is_canceled', 0)
            ->whereDate('start_time', $date->toDateString())
            ->first();

        if ($existing) {
            return $existing->fresh(['subTasks']);
        }

        $end = Carbon::parse($parent->end_time)
            ->timezone(EmployeeVisibleTasks::TIMEZONE)
            ->startOfDay();

        if ($date->lt($anchor) || $date->gt($end)) {
            return $parent;
        }

        return $this->createChildForDate($parent, $date);
    }

    public function createChildForDate(EmployeeTask $parent, Carbon $date): EmployeeTask
    {
        $parent->loadMissing('subTasks');
        $anchorTime = Carbon::parse($parent->start_time)->timezone(EmployeeVisibleTasks::TIMEZONE);
        $newStart = $date->copy()->setTime(
            $anchorTime->hour,
            $anchorTime->minute,
            $anchorTime->second
        );

        $data = $parent->replicate()->toArray();
        $data['parent_id'] = $parent->id;
        $data['start_time'] = $newStart->format('Y-m-d H:i:s');
        $data['end_time'] = Carbon::parse($parent->end_time)->format('Y-m-d H:i:s');
        $data['status'] = EmployeeTaskStatus::Pending->value;
        $data['completed_by_employee_id'] = null;
        $data['submitted_at'] = null;
        $data['reviewed_at'] = null;
        $data['started_at'] = null;
        $data['employee_img'] = null;
        unset($data['display_number']);

        /** @var EmployeeTask $child */
        $child = EmployeeTask::create($data);

        app(EmployeeTaskAssigneeService::class)->copyFromParent($parent, $child);

        foreach ($parent->subTasks as $subtask) {
            $subData = $subtask->replicate()->toArray();
            $subData['employee_task_id'] = $child->id;
            $subData['status'] = 'pending';
            $subData['employee_img'] = null;
            if (array_key_exists('completed_by_employee_id', $subData)) {
                $subData['completed_by_employee_id'] = null;
            }
            EmployeeSubTask::create($subData);
        }

        return $child->fresh(['subTasks']);
    }

    /**
     * Recurring parent rows are templates; keep them active after a day instance completes.
     */
    public function keepParentTemplateActive(EmployeeTask $parent, EmployeeTask $completedInstance): void
    {
        if (! $this->isRecurringParent($parent)) {
            return;
        }

        $anchor = Carbon::parse($parent->start_time)
            ->timezone(EmployeeVisibleTasks::TIMEZONE)
            ->startOfDay();
        $completedDay = Carbon::parse($completedInstance->start_time)
            ->timezone(EmployeeVisibleTasks::TIMEZONE)
            ->startOfDay();

        if ((int) $completedInstance->id === (int) $parent->id && $completedDay->equalTo($anchor)) {
            return;
        }

        $parent->refresh();

        if (! in_array($parent->status, [
            EmployeeTaskStatus::Completed->value,
            EmployeeTaskStatus::WaitingReview->value,
        ], true)) {
            return;
        }

        $parent->update([
            'status' => EmployeeTaskStatus::Ongoing->value,
            'completed_by_employee_id' => null,
            'submitted_at' => null,
            'reviewed_at' => null,
        ]);
    }

    public function parseTaskDate(?string $taskDate, EmployeeTask $fallbackTask): Carbon
    {
        if ($taskDate) {
            return Carbon::parse($taskDate)->timezone(EmployeeVisibleTasks::TIMEZONE)->startOfDay();
        }

        return Carbon::parse($fallbackTask->start_time)
            ->timezone(EmployeeVisibleTasks::TIMEZONE)
            ->startOfDay();
    }

    /**
     * Reset parent template when completion was stored on the wrong row/day.
     */
    public function repairTemplateIfNeeded(EmployeeTask $parent): void
    {
        if (! $this->isRecurringParent($parent)) {
            return;
        }

        if (! in_array($parent->status, [
            EmployeeTaskStatus::Completed->value,
            EmployeeTaskStatus::WaitingReview->value,
        ], true)) {
            return;
        }

        $anchor = Carbon::parse($parent->start_time)
            ->timezone(EmployeeVisibleTasks::TIMEZONE)
            ->startOfDay();

        $actionDay = null;
        if (! empty($parent->submitted_at)) {
            $actionDay = Carbon::parse($parent->submitted_at)
                ->timezone(EmployeeVisibleTasks::TIMEZONE)
                ->startOfDay();
        } elseif (! empty($parent->reviewed_at)) {
            $actionDay = Carbon::parse($parent->reviewed_at)
                ->timezone(EmployeeVisibleTasks::TIMEZONE)
                ->startOfDay();
        }

        if ($actionDay && $actionDay->equalTo($anchor)) {
            return;
        }

        $parent->update([
            'status' => EmployeeTaskStatus::Ongoing->value,
            'completed_by_employee_id' => null,
            'submitted_at' => null,
            'reviewed_at' => null,
        ]);
    }
}
