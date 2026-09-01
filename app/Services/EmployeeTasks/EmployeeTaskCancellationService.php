<?php

namespace App\Services\EmployeeTasks;

use App\Models\EmployeeTask;
use App\Models\EmployeeTaskOccurrence;
use App\Models\EmployeeTaskTemplate;
use Carbon\Carbon;
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
                    $this->cancelLegacyFamilyFromToday($occurrence->legacy_task_id);
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

        $this->cancelLegacyFamilyFromToday($task->id);

            return $task->fresh();
        });
    }

    private function cancelTemplateSeries(int $templateId): void
    {
        $template = EmployeeTaskTemplate::query()
            ->lockForUpdate()
            ->findOrFail($templateId);

        $template->update(['is_active' => false]);

        $today = Carbon::now('Asia/Hebron')->toDateString();

        EmployeeTaskOccurrence::query()
            ->where('template_id', $templateId)
            ->whereDate('scheduled_date', '>=', $today)
            ->whereNotIn('status', ['completed', 'waiting_review'])
            ->whereNull('started_at')
            ->whereNull('submitted_at')
            ->whereNull('reviewed_at')
            ->whereNull('completed_at')
            ->whereNull('completed_by_employee_id')
            ->whereDoesntHave('subtasks', fn ($query) => $query->where('status', '!=', 'pending'))
            ->update(['is_canceled' => true]);

        EmployeeTask::query()
            ->where('template_id', $templateId)
            ->whereDate('start_time', '>=', $today)
            ->whereNotIn('status', ['completed', 'waiting_review'])
            ->whereNull('started_at')
            ->whereNull('submitted_at')
            ->whereNull('reviewed_at')
            ->whereNull('completed_by_employee_id')
            ->whereDoesntHave('subTasks', fn ($query) => $query->where('status', '!=', 'pending'))
            ->update(['is_canceled' => true]);
    }

    private function cancelLegacyFamilyFromToday(int $taskId): void
    {
        $task = EmployeeTask::query()
            ->lockForUpdate()
            ->findOrFail($taskId);
        $rootId = (int) ($task->parent_id ?: $task->id);
        $today = Carbon::now('Asia/Hebron')->toDateString();

        EmployeeTask::query()
            ->where(fn ($query) => $query->where('id', $rootId)->orWhere('parent_id', $rootId))
            ->whereDate('start_time', '>=', $today)
            ->whereNotIn('status', ['completed', 'waiting_review'])
            ->whereNull('started_at')
            ->whereNull('submitted_at')
            ->whereNull('reviewed_at')
            ->whereNull('completed_by_employee_id')
            ->whereDoesntHave('subTasks', fn ($query) => $query->where('status', '!=', 'pending'))
            ->update(['is_canceled' => true]);
    }
}
