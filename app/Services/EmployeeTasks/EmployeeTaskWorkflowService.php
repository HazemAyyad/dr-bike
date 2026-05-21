<?php

namespace App\Services\EmployeeTasks;

use App\Enums\EmployeeTaskStatus;
use App\Models\EmployeeDetail;
use App\Models\EmployeeSubTask;
use App\Models\EmployeeTask;
use App\Models\EmployeeTaskOccurrence;
use App\Models\EmployeeTaskOccurrenceSubtask;
use App\Models\EmployeeTaskTimeline;
use App\Services\AdminNotificationService;
use App\Services\EmployeeNotificationService;
use App\Services\EmployeeTasks\EmployeeTaskNotificationService;
use App\Support\EmployeeProofImages;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

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

        if (! $this->requiresAdminReview($task)) {
            return $this->approveTask($task);
        }

        $task->update([
            'status' => EmployeeTaskStatus::WaitingReview->value,
            'submitted_at' => now(),
            'notes' => $employeeNotes ?? $task->notes,
        ]);

        $this->timeline->recordForTask($task, EmployeeTaskTimeline::EVENT_SUBMITTED);

        $fresh = $task->fresh(['employee']);
        $this->notifyAdminTaskSubmitted($fresh);
        $this->notifyDailyTasksCompletedIfApplicable($fresh->employee);

        return $fresh;
    }

    public function submitOccurrenceForReview(EmployeeTaskOccurrence $occurrence, ?string $employeeNotes = null): EmployeeTaskOccurrence
    {
        $this->assertOccurrenceSubtasksComplete($occurrence);
        $this->assertOccurrenceProofIfRequired($occurrence);

        if (! $this->requiresAdminReview($occurrence)) {
            return $this->approveOccurrence($occurrence);
        }

        $occurrence->update([
            'status' => EmployeeTaskStatus::WaitingReview->value,
            'submitted_at' => now(),
            'employee_notes' => $employeeNotes ?? $occurrence->employee_notes,
        ]);

        $this->timeline->recordForOccurrence($occurrence, EmployeeTaskTimeline::EVENT_SUBMITTED);

        $fresh = $occurrence->fresh(['employee']);
        $this->notifyAdminOccurrenceSubmitted($fresh);
        $this->notifyDailyTasksCompletedIfApplicable($fresh->employee);

        return $fresh;
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

            $fresh = $task->fresh(['employee']);
            $this->notifyEmployeeTaskApproved($fresh->employee, $fresh->name, (int) $fresh->id, null);
            $this->notifyDailyTasksCompletedIfApplicable($fresh->employee);

            return $fresh;
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

            $fresh = $occurrence->fresh(['employee']);
            $this->notifyEmployeeTaskApproved(
                $fresh->employee,
                $fresh->name,
                (int) ($fresh->legacy_task_id ?? $fresh->id),
                (int) $fresh->id
            );
            $this->notifyDailyTasksCompletedIfApplicable($fresh->employee);

            return $fresh;
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

        $fresh = $task->fresh(['employee']);
        $this->notifyEmployeeTaskRejected($fresh->employee, $fresh->name, $rejectionNotes, (int) $fresh->id, null);

        return $fresh;
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

        $fresh = $occurrence->fresh(['employee']);
        $this->notifyEmployeeTaskRejected(
            $fresh->employee,
            $fresh->name,
            $rejectionNotes,
            (int) ($fresh->legacy_task_id ?? $fresh->id),
            (int) $fresh->id
        );

        return $fresh;
    }

    public function completeSubtask(EmployeeSubTask $subTask): EmployeeSubTask
    {
        if ($subTask->requires_image ?? $subTask->is_forced_to_upload_img) {
            if (! \App\Support\TaskMediaFiles::hasProof($subTask->employee_img)) {
                throw new \RuntimeException(__('messages.employee_image_required'));
            }
        }

        $subTask->update(['status' => 'completed']);
        $task = $subTask->employeeTask;
        $this->timeline->recordForTask($task, EmployeeTaskTimeline::EVENT_SUBTASK_COMPLETED, $subTask->name);

        $fresh = $subTask->fresh();
        $this->notifyAdminLegacySubtaskCompleted($fresh);
        $subTask->loadMissing('employeeTask.employee');
        $this->notifyDailyTasksCompletedIfApplicable($subTask->employeeTask?->employee);

        return $fresh;
    }

    public function completeOccurrenceSubtask(EmployeeTaskOccurrenceSubtask $subTask): EmployeeTaskOccurrenceSubtask
    {
        if ($subTask->requires_image && ! \App\Support\TaskMediaFiles::hasProof($subTask->employee_img)) {
            throw new \RuntimeException(__('messages.employee_image_required'));
        }

        $subTask->update(['status' => 'completed']);
        $this->timeline->recordForOccurrence(
            $subTask->occurrence,
            EmployeeTaskTimeline::EVENT_SUBTASK_COMPLETED,
            $subTask->name
        );

        $fresh = $subTask->fresh();
        $this->notifyAdminOccurrenceSubtaskCompleted($fresh);
        $subTask->loadMissing('occurrence.employee');
        $this->notifyDailyTasksCompletedIfApplicable($subTask->occurrence?->employee);

        return $fresh;
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
        if ($task->is_forced_to_upload_img && ! \App\Support\TaskMediaFiles::hasProof($task->employee_img)) {
            throw new \RuntimeException(__('messages.employee_image_required'));
        }
    }

    private function assertOccurrenceProofIfRequired(EmployeeTaskOccurrence $occurrence): void
    {
        if ($occurrence->is_forced_to_upload_img && ! \App\Support\TaskMediaFiles::hasProof($occurrence->employee_img)) {
            throw new \RuntimeException(__('messages.employee_image_required'));
        }
    }

    private function requiresAdminReview(Model $model): bool
    {
        if (! Schema::hasColumn($model->getTable(), 'requires_admin_review')) {
            return true;
        }

        return (bool) ($model->requires_admin_review ?? true);
    }

    private function calculateSubtaskBonus(EmployeeTask $task): int
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('sub_employee_tasks', 'bonus_points')) {
            return 0;
        }

        return (int) $task->subTasks()->where('status', 'completed')->sum('bonus_points');
    }

    private function notifyAdminTaskSubmitted(EmployeeTask $task): void
    {
        try {
            $task->loadMissing('employee.user');
            if ($task->employee) {
                app(AdminNotificationService::class)->notifyTaskSubmittedForReview($task->employee, $task);
            }
        } catch (\Throwable $e) {
            Log::warning('admin_notification.task_submitted_failed', [
                'task_id' => $task->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function notifyAdminOccurrenceSubmitted(EmployeeTaskOccurrence $occurrence): void
    {
        try {
            $occurrence->loadMissing('employee.user');
            if ($occurrence->employee) {
                app(AdminNotificationService::class)->notifyOccurrenceSubmittedForReview(
                    $occurrence->employee,
                    $occurrence
                );
            }
        } catch (\Throwable $e) {
            Log::warning('admin_notification.occurrence_submitted_failed', [
                'occurrence_id' => $occurrence->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function notifyAdminLegacySubtaskCompleted(\App\Models\EmployeeSubTask $subTask): void
    {
        try {
            $subTask->loadMissing('employeeTask.employee.user');
            $employee = $subTask->employeeTask?->employee;
            if ($employee) {
                app(AdminNotificationService::class)->notifyLegacySubtaskCompleted($employee, $subTask);
            }
        } catch (\Throwable $e) {
            Log::warning('admin_notification.subtask_completed_failed', [
                'sub_task_id' => $subTask->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function notifyAdminOccurrenceSubtaskCompleted(
        \App\Models\EmployeeTaskOccurrenceSubtask $subTask
    ): void {
        try {
            $subTask->loadMissing('occurrence.employee.user');
            $employee = $subTask->occurrence?->employee;
            if ($employee) {
                app(AdminNotificationService::class)->notifyOccurrenceSubtaskCompleted($employee, $subTask);
            }
        } catch (\Throwable $e) {
            Log::warning('admin_notification.occurrence_subtask_completed_failed', [
                'sub_task_id' => $subTask->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function notifyEmployeeTaskApproved(
        ?\App\Models\EmployeeDetail $employee,
        string $taskName,
        int $legacyTaskId,
        ?int $occurrenceId
    ): void {
        if (! $employee) {
            return;
        }
        try {
            app(EmployeeTaskNotificationService::class)->notifyTaskApproved(
                $employee,
                $taskName,
                $legacyTaskId,
                $occurrenceId
            );
        } catch (\Throwable $e) {
            Log::warning('employee_notification.task_approved_failed', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function notifyEmployeeTaskRejected(
        ?\App\Models\EmployeeDetail $employee,
        string $taskName,
        string $rejectionNotes,
        int $legacyTaskId,
        ?int $occurrenceId
    ): void {
        if (! $employee) {
            return;
        }
        try {
            app(EmployeeTaskNotificationService::class)->notifyTaskRejected(
                $employee,
                $taskName,
                $rejectionNotes,
                $legacyTaskId,
                $occurrenceId
            );
        } catch (\Throwable $e) {
            Log::warning('employee_notification.task_rejected_failed', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function notifyDailyTasksCompletedIfApplicable(?EmployeeDetail $employee): void
    {
        if ($employee === null) {
            return;
        }
        try {
            app(EmployeeNotificationService::class)->maybeNotifyAllDailyTasksCompleted($employee);
        } catch (\Throwable $e) {
            Log::warning('employee_notification.daily_tasks_complete_failed', [
                'employee_id' => $employee->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
