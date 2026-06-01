<?php

namespace App\Services\EmployeeTasks;

use App\Enums\EmployeeTaskStatus;
use App\Models\EmployeeSubTask;
use App\Models\EmployeeTask;
use App\Models\EmployeeTaskOccurrence;
use App\Models\EmployeeTaskOccurrenceSubtask;
use App\Support\TaskMediaFiles;

/**
 * After proof upload on a subtask, mark it completed and optionally submit the parent task.
 *
 * @return array{
 *     subtask_completed: bool,
 *     all_subtasks_done: bool,
 *     auto_submitted: bool,
 *     needs_main_proof: bool
 * }
 */
class EmployeeSubtaskProofCompletionService
{
    public function __construct(
        private readonly EmployeeTaskWorkflowService $workflow
    ) {}

    public function afterLegacySubtaskProofUpload(EmployeeSubTask $subTask): array
    {
        $subTask = $subTask->fresh();
        $meta = $this->emptyMeta();

        if ($subTask->status === 'completed') {
            $meta['subtask_completed'] = true;

            return $meta;
        }

        if (! TaskMediaFiles::hasRequiredProof(
            $subTask->employee_img,
            $subTask->proof_media_type ?? null,
            (bool) $subTask->is_forced_to_upload_img
        )) {
            return $meta;
        }

        $otherPending = EmployeeSubTask::query()
            ->where('employee_task_id', $subTask->employee_task_id)
            ->where('id', '!=', $subTask->id)
            ->where('status', '!=', 'completed')
            ->exists();

        if (! $otherPending) {
            $this->workflow->completeSubtask($subTask);
            $employeeTask = EmployeeTask::findOrFail($subTask->employee_task_id)->fresh();

            if ($employeeTask->status === EmployeeTaskStatus::Pending->value) {
                $this->workflow->startTask($employeeTask);
                $employeeTask->refresh();
            }

            $meta['subtask_completed'] = true;
            $meta['all_subtasks_done'] = true;

            if (! TaskMediaFiles::hasRequiredProof(
                $employeeTask->employee_img,
                $employeeTask->proof_media_type ?? null,
                (bool) $employeeTask->is_forced_to_upload_img
            )) {
                $meta['needs_main_proof'] = true;

                return $meta;
            }

            try {
                $this->workflow->submitTaskForReview($employeeTask);
                $meta['auto_submitted'] = true;
            } catch (\Throwable) {
                // Subtask stays completed; employee can submit manually.
            }

            return $meta;
        }

        $this->workflow->completeSubtask($subTask);
        $meta['subtask_completed'] = true;

        return $meta;
    }

    public function afterOccurrenceSubtaskProofUpload(EmployeeTaskOccurrenceSubtask $subTask): array
    {
        $subTask = $subTask->fresh();
        $meta = $this->emptyMeta();

        if ($subTask->status === 'completed') {
            $meta['subtask_completed'] = true;

            return $meta;
        }

        if (! TaskMediaFiles::hasRequiredProof(
            $subTask->employee_img,
            $subTask->proof_media_type ?? null,
            (bool) $subTask->requires_image
        )) {
            return $meta;
        }

        $this->workflow->completeOccurrenceSubtask($subTask);
        $meta['subtask_completed'] = true;

        $occurrence = $subTask->occurrence->fresh();
        $pending = $occurrence->subtasks()->where('status', '!=', 'completed')->exists();

        if (! $pending) {
            $meta['all_subtasks_done'] = true;

            if (! TaskMediaFiles::hasRequiredProof(
                $occurrence->employee_img,
                $occurrence->proof_media_type ?? null,
                (bool) $occurrence->is_forced_to_upload_img
            )) {
                $meta['needs_main_proof'] = true;

                return $meta;
            }

            try {
                $this->workflow->submitOccurrenceForReview($occurrence);
                $meta['auto_submitted'] = true;
            } catch (\Throwable) {
                // Occurrence subtasks done; main proof or submit can be retried.
            }
        }

        return $meta;
    }

    /**
     * @return array{subtask_completed: bool, all_subtasks_done: bool, auto_submitted: bool, needs_main_proof: bool}
     */
    private function emptyMeta(): array
    {
        return [
            'subtask_completed' => false,
            'all_subtasks_done' => false,
            'auto_submitted' => false,
            'needs_main_proof' => false,
        ];
    }
}
