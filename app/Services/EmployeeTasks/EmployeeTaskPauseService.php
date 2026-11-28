<?php

namespace App\Services\EmployeeTasks;

use App\Models\EmployeeTask;
use App\Models\EmployeeTaskOccurrence;
use App\Models\EmployeeTaskTemplate;
use Illuminate\Support\Facades\DB;

class EmployeeTaskPauseService
{
    /** Pause the whole task family while preserving progress, cancellation and recurrence settings. */
    public function setPaused(?int $taskId, ?int $occurrenceId, bool $paused): void
    {
        DB::transaction(function () use ($taskId, $occurrenceId, $paused) {
            $occurrence = $occurrenceId
                ? EmployeeTaskOccurrence::query()->lockForUpdate()->findOrFail($occurrenceId)
                : null;
            $taskId = $occurrence?->legacy_task_id ?: $taskId;
            $task = $taskId ? EmployeeTask::findOrFail($taskId) : null;
            $rootId = $task ? (int) ($task->parent_id ?: $task->id) : null;
            $root = $rootId ? EmployeeTask::query()->lockForUpdate()->findOrFail($rootId) : null;
            $templateId = $occurrence?->template_id ?: ($root?->template_id ?: $task?->template_id);

            if ($templateId) {
                EmployeeTaskTemplate::query()->lockForUpdate()->findOrFail($templateId)
                    ->update(['is_paused' => $paused]);
            }

            $family = EmployeeTask::query()->where(function ($query) use ($rootId, $templateId) {
                $query->whereRaw('1 = 0');
                if ($rootId) {
                    $query->orWhere('id', $rootId)->orWhere('parent_id', $rootId);
                }
                if ($templateId) {
                    $query->orWhere('template_id', $templateId);
                }
            });
            $familyIds = (clone $family)->pluck('id');
            $family->update(['is_paused' => $paused]);

            EmployeeTaskOccurrence::query()->where(function ($query) use ($familyIds, $templateId, $occurrenceId) {
                $query->whereIn('legacy_task_id', $familyIds);
                if ($templateId) {
                    $query->orWhere('template_id', $templateId);
                }
                if ($occurrenceId) {
                    $query->orWhere('id', $occurrenceId);
                }
            })->update(['is_paused' => $paused]);
        });
    }
}
