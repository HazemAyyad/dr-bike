<?php

namespace App\Services\EmployeeTasks;

use App\Models\EmployeeTask;
use App\Models\EmployeeTaskOccurrence;
use App\Models\EmployeeTaskTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeTaskCancellationService
{
    public function cancelOccurrence(int $occurrenceId): EmployeeTaskOccurrence
    {
        return DB::transaction(function () use ($occurrenceId) {
            $occurrence = EmployeeTaskOccurrence::query()
                ->lockForUpdate()
                ->findOrFail($occurrenceId);

            $occurrence->update(['is_canceled' => true]);

            return $occurrence->fresh();
        });
    }

    public function cancelLegacyTask(int $taskId): EmployeeTask
    {
        return DB::transaction(function () use ($taskId) {
            $task = EmployeeTask::query()
                ->lockForUpdate()
                ->findOrFail($taskId);

            $task->update(['is_canceled' => true]);

            return $task->fresh();
        });
    }

    public function cancelOccurrenceSeries(int $occurrenceId): EmployeeTaskOccurrence
    {
        return DB::transaction(function () use ($occurrenceId) {
            $occurrence = EmployeeTaskOccurrence::query()
                ->lockForUpdate()
                ->findOrFail($occurrenceId);

            if (! $occurrence->template_id) {
                if ($occurrence->legacy_task_id) {
                    $this->cancelLegacyFamily($occurrence->legacy_task_id);
                    $occurrence->update(['is_canceled' => true]);

                    return $occurrence->fresh();
                }

                throw ValidationException::withMessages([
                    'occurrence_id' => __('messages.validation_failed'),
                ]);
            }

            $this->cancelTemplateSeries((int) $occurrence->template_id);

            return $occurrence->fresh();
        });
    }

    public function cancelLegacySeries(int $taskId): EmployeeTask
    {
        return DB::transaction(function () use ($taskId) {
            $task = EmployeeTask::query()
                ->lockForUpdate()
                ->findOrFail($taskId);

            if ($task->template_id) {
                $this->cancelTemplateSeries((int) $task->template_id);
            }

            $this->cancelLegacyFamily($task->id);

            return $task->fresh();
        });
    }

    private function cancelTemplateSeries(int $templateId): void
    {
        $template = EmployeeTaskTemplate::query()
            ->lockForUpdate()
            ->findOrFail($templateId);

        $template->update(['is_active' => false]);

        EmployeeTaskOccurrence::query()
            ->where('template_id', $templateId)
            ->update(['is_canceled' => true]);

        EmployeeTask::query()
            ->where('template_id', $templateId)
            ->update(['is_canceled' => true]);
    }

    private function cancelLegacyFamily(int $taskId): void
    {
        $task = EmployeeTask::query()
            ->lockForUpdate()
            ->findOrFail($taskId);
        $rootId = (int) ($task->parent_id ?: $task->id);

        EmployeeTask::query()
            ->where('id', $rootId)
            ->orWhere('parent_id', $rootId)
            ->update(['is_canceled' => true]);
    }
}
