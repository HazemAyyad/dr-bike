<?php

namespace App\Services\EmployeeTasks;

use App\Enums\EmployeeTaskStatus;
use App\Models\EmployeeDetail;
use App\Models\EmployeeSubTask;
use App\Models\EmployeeTask;
use App\Models\EmployeeTaskOccurrence;
use App\Models\EmployeeTaskOccurrenceSubtask;
use App\Models\EmployeeTaskTimeline;
use Illuminate\Support\Facades\DB;

class EmployeeTaskWorkflowService
{
    public function __construct(
        private readonly EmployeeTaskTimelineService $timeline
    ) {}

    public function startTask(EmployeeTask $task): EmployeeTask
    {
        $status = EmployeeTaskStatus::normalize($task->status);

        if (! in_array($status, [EmployeeTaskStatus::Pending, EmployeeTaskStatus::Overdue, EmployeeTaskStatus::Ongoing], true)) {
            throw new \RuntimeException(__('messages.task_cannot_be_started'));
        }

        $task->update([
            'status' => EmployeeTaskStatus::InProgress->value,
            'started_at' => now(),
        ]);

        $this->timeline->recordForTask($task, EmployeeTaskTimeline::EVENT_STARTED);

        return $task->fresh();
    }

    public function startOccurrence(EmployeeTaskOccurrence $occurrence): EmployeeTaskOccurrence
    {
        $status = EmployeeTaskStatus::normalize($occurrence->status);

        if (! in_array($status, [EmployeeTaskStatus::Pending, EmployeeTaskStatus::Overdue], true)) {
            throw new \RuntimeException(__('messages.task_cannot_be_started'));
        }

        $occurrence->update([
            'status' => EmployeeTaskStatus::InProgress->value,
            'started_at' => now(),
        ]);

        $this->timeline->recordForOccurrence($occurrence, EmployeeTaskTimeline::EVENT_STARTED);

        return $occurrence->fresh();
    }

    public function submitTaskForReview(EmployeeTask $task, ?string $employeeNotes = null): EmployeeTask
    {
        $this->assertSubtasksComplete($task);
        $this->assertProofIfRequired($task);

        $task->update([
            'status' => EmployeeTaskStatus::WaitingReview->value,
            'submitted_at' => now(),
            'notes' => $employeeNotes ?? $task->notes,
        ]);

        $this->timeline->recordForTask($task, EmployeeTaskTimeline::EVENT_SUBMITTED);

        return $task->fresh();
    }

    public function submitOccurrenceForReview(EmployeeTaskOccurrence $occurrence, ?string $employeeNotes = null): EmployeeTaskOccurrence
    {
        $this->assertOccurrenceSubtasksComplete($occurrence);
        $this->assertOccurrenceProofIfRequired($occurrence);

        $occurrence->update([
            'status' => EmployeeTaskStatus::WaitingReview->value,
            'submitted_at' => now(),
            'employee_notes' => $employeeNotes ?? $occurrence->employee_notes,
        ]);

        $this->timeline->recordForOccurrence($occurrence, EmployeeTaskTimeline::EVENT_SUBMITTED);

        return $occurrence->fresh();
    }

    public function approveTask(EmployeeTask $task): EmployeeTask
    {
        return DB::transaction(function () use ($task) {
            $task->update([
                'status' => EmployeeTaskStatus::Completed->value,
                'reviewed_at' => now(),
            ]);

            $employee = $task->employee;
            if ($employee) {
                $bonus = $this->calculateSubtaskBonus($task);
                $employee->update(['points' => $employee->points + $task->points + $bonus]);
            }

            $this->timeline->recordForTask($task, EmployeeTaskTimeline::EVENT_APPROVED);

            return $task->fresh();
        });
    }

    public function approveOccurrence(EmployeeTaskOccurrence $occurrence): EmployeeTaskOccurrence
    {
        return DB::transaction(function () use ($occurrence) {
            $occurrence->update([
                'status' => EmployeeTaskStatus::Completed->value,
                'reviewed_at' => now(),
                'completed_at' => now(),
            ]);

            $employee = $occurrence->employee;
            if ($employee) {
                $bonus = $occurrence->subtasks()->where('status', 'completed')->sum('bonus_points');
                $employee->update(['points' => $employee->points + $occurrence->points + $bonus]);
            }

            $this->timeline->recordForOccurrence($occurrence, EmployeeTaskTimeline::EVENT_APPROVED);

            return $occurrence->fresh();
        });
    }

    public function rejectTask(EmployeeTask $task, string $rejectionNotes): EmployeeTask
    {
        $task->update([
            'status' => EmployeeTaskStatus::InProgress->value,
            'rejection_notes' => $rejectionNotes,
            'reviewed_at' => now(),
            'submitted_at' => null,
        ]);

        $this->timeline->recordForTask($task, EmployeeTaskTimeline::EVENT_REJECTED, $rejectionNotes);

        return $task->fresh();
    }

    public function rejectOccurrence(EmployeeTaskOccurrence $occurrence, string $rejectionNotes): EmployeeTaskOccurrence
    {
        $occurrence->update([
            'status' => EmployeeTaskStatus::InProgress->value,
            'rejection_notes' => $rejectionNotes,
            'reviewed_at' => now(),
            'submitted_at' => null,
        ]);

        $this->timeline->recordForOccurrence($occurrence, EmployeeTaskTimeline::EVENT_REJECTED, $rejectionNotes);

        return $occurrence->fresh();
    }

    public function completeSubtask(EmployeeSubTask $subTask): EmployeeSubTask
    {
        if ($subTask->requires_image ?? $subTask->is_forced_to_upload_img) {
            if (empty($subTask->employee_img)) {
                throw new \RuntimeException(__('messages.employee_image_required'));
            }
        }

        $subTask->update(['status' => 'completed']);
        $task = $subTask->employeeTask;
        $this->timeline->recordForTask($task, EmployeeTaskTimeline::EVENT_SUBTASK_COMPLETED, $subTask->name);

        return $subTask->fresh();
    }

    public function completeOccurrenceSubtask(EmployeeTaskOccurrenceSubtask $subTask): EmployeeTaskOccurrenceSubtask
    {
        if ($subTask->requires_image && empty($subTask->employee_img)) {
            throw new \RuntimeException(__('messages.employee_image_required'));
        }

        $subTask->update(['status' => 'completed']);
        $this->timeline->recordForOccurrence(
            $subTask->occurrence,
            EmployeeTaskTimeline::EVENT_SUBTASK_COMPLETED,
            $subTask->name
        );

        return $subTask->fresh();
    }

    private function assertSubtasksComplete(EmployeeTask $task): void
    {
        if ($task->subTasks()->where('status', '!=', 'completed')->exists()) {
            throw new \RuntimeException(__('messages.can_not_complete_employee_task'));
        }
    }

    private function assertOccurrenceSubtasksComplete(EmployeeTaskOccurrence $occurrence): void
    {
        if ($occurrence->subtasks()->where('status', '!=', 'completed')->exists()) {
            throw new \RuntimeException(__('messages.can_not_complete_employee_task'));
        }
    }

    private function assertProofIfRequired(EmployeeTask $task): void
    {
        if ($task->is_forced_to_upload_img && empty($task->employee_img)) {
            throw new \RuntimeException(__('messages.employee_image_required'));
        }
    }

    private function assertOccurrenceProofIfRequired(EmployeeTaskOccurrence $occurrence): void
    {
        if ($occurrence->is_forced_to_upload_img && empty($occurrence->employee_img)) {
            throw new \RuntimeException(__('messages.employee_image_required'));
        }
    }

    private function calculateSubtaskBonus(EmployeeTask $task): int
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('sub_employee_tasks', 'bonus_points')) {
            return 0;
        }

        return (int) $task->subTasks()->where('status', 'completed')->sum('bonus_points');
    }
}
