<?php

namespace App\Services\EmployeeTasks;

use App\Enums\EmployeeTaskStatus;
use App\Models\EmployeeDetail;
use App\Models\EmployeeSubTask;
use App\Models\EmployeeTask;
use App\Models\EmployeeTaskOccurrence;
use App\Models\EmployeeTaskOccurrenceSubtask;
use App\Models\EmployeeTaskTimeline;
use App\Models\EmployeePointCategory;
use App\Models\EmployeePointsLog;
use App\Services\AdminNotificationService;
use App\Services\EmployeeNotificationService;
use App\Services\EmployeePointsService;
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

        $actorId = auth()->user()?->employee?->id;

        $task->update([
            'status' => EmployeeTaskStatus::WaitingReview->value,
            'submitted_at' => now(),
            'notes' => $employeeNotes ?? $task->notes,
            'completed_by_employee_id' => $actorId ?? $task->completed_by_employee_id,
        ]);

        $this->timeline->recordForTask($task, EmployeeTaskTimeline::EVENT_SUBMITTED);

        $fresh = $task->fresh(['employee']);
        $this->notifyAdminTaskSubmitted($fresh);
        $this->notifyDailyTasksCompletedIfApplicable($fresh->employee);
        if ($actorId) {
            app(EmployeeTaskNotificationService::class)->notifyCoAssigneesMainTaskSubmitted(
                $fresh,
                (int) $actorId,
                null
            );
        }

        return $fresh;
    }

    public function submitOccurrenceForReview(EmployeeTaskOccurrence $occurrence, ?string $employeeNotes = null): EmployeeTaskOccurrence
    {
        $this->assertOccurrenceSubtasksComplete($occurrence);
        $this->assertOccurrenceProofIfRequired($occurrence);

        if (! $this->requiresAdminReview($occurrence)) {
            return $this->approveOccurrence($occurrence);
        }

        $actorId = auth()->user()?->employee?->id;

        $occurrence->update([
            'status' => EmployeeTaskStatus::WaitingReview->value,
            'submitted_at' => now(),
            'employee_notes' => $employeeNotes ?? $occurrence->employee_notes,
            'completed_by_employee_id' => $actorId ?? $occurrence->completed_by_employee_id,
        ]);

        $this->timeline->recordForOccurrence($occurrence, EmployeeTaskTimeline::EVENT_SUBMITTED);

        $fresh = $occurrence->fresh(['employee']);
        $this->notifyAdminOccurrenceSubmitted($fresh);
        $this->notifyDailyTasksCompletedIfApplicable($fresh->employee);
        $legacy = $this->legacyTaskForOccurrence($fresh);
        if ($actorId && $legacy) {
            app(EmployeeTaskNotificationService::class)->notifyCoAssigneesMainTaskSubmitted(
                $legacy,
                (int) $actorId,
                (int) $fresh->id
            );
        }

        return $fresh;
    }

    public function approveTask(EmployeeTask $task): EmployeeTask
    {
        return DB::transaction(function () use ($task) {
            $wasCompleted = EmployeeTaskStatus::normalize($task->status) === EmployeeTaskStatus::Completed;
            $actorId = auth()->user()?->employee?->id;

            $task->update([
                'status' => EmployeeTaskStatus::Completed->value,
                'reviewed_at' => now(),
                'completed_by_employee_id' => $task->completed_by_employee_id ?? $actorId,
            ]);

            if (! $wasCompleted) {
                $this->awardCompletionPointsForTask($task->fresh());
            }

            $this->timeline->recordForTask($task, EmployeeTaskTimeline::EVENT_APPROVED);

            $fresh = $task->fresh(['employee']);
            $pointsRecipient = $this->resolvePointsRecipient($fresh, null);
            $this->notifyEmployeeTaskApproved($pointsRecipient, $fresh->name, (int) $fresh->id, null);
            $this->notifyDailyTasksCompletedIfApplicable($fresh->employee);
            $completedBy = (int) ($fresh->completed_by_employee_id ?? $actorId ?? 0);
            if ($completedBy > 0) {
                app(EmployeeTaskNotificationService::class)->notifyCoAssigneesMainTaskCompleted(
                    $fresh,
                    $completedBy,
                    null
                );
            }

            return $fresh;
        });
    }

    public function approveOccurrence(EmployeeTaskOccurrence $occurrence): EmployeeTaskOccurrence
    {
        return DB::transaction(function () use ($occurrence) {
            $wasCompleted = EmployeeTaskStatus::normalize($occurrence->status) === EmployeeTaskStatus::Completed;
            $actorId = auth()->user()?->employee?->id;

            $occurrence->update([
                'status' => EmployeeTaskStatus::Completed->value,
                'reviewed_at' => now(),
                'completed_at' => now(),
                'completed_by_employee_id' => $occurrence->completed_by_employee_id ?? $actorId,
            ]);

            if (! $wasCompleted) {
                $this->awardCompletionPointsForOccurrence($occurrence->fresh());
            }

            $this->timeline->recordForOccurrence($occurrence, EmployeeTaskTimeline::EVENT_APPROVED);

            $fresh = $occurrence->fresh(['employee']);
            $pointsRecipient = $this->resolvePointsRecipient(null, $fresh);
            $this->notifyEmployeeTaskApproved(
                $pointsRecipient,
                $fresh->name,
                (int) ($fresh->legacy_task_id ?? $fresh->id),
                (int) $fresh->id
            );
            $this->notifyDailyTasksCompletedIfApplicable($fresh->employee);
            $legacy = $this->legacyTaskForOccurrence($fresh);
            $completedBy = (int) ($fresh->completed_by_employee_id ?? $actorId ?? 0);
            if ($legacy && $completedBy > 0) {
                app(EmployeeTaskNotificationService::class)->notifyCoAssigneesMainTaskCompleted(
                    $legacy,
                    $completedBy,
                    (int) $fresh->id
                );
            }

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
        $requiresProof = (bool) ($subTask->requires_image ?? $subTask->is_forced_to_upload_img);
        if ($requiresProof) {
            if (! \App\Support\TaskMediaFiles::hasRequiredProof($subTask->employee_img, $subTask->proof_media_type ?? null, $requiresProof)) {
                throw new \RuntimeException(__('messages.employee_image_required'));
            }
        }

        $actorId = (int) (auth()->user()?->employee?->id ?? 0);
        $payload = ['status' => 'completed'];
        if (Schema::hasColumn('sub_employee_tasks', 'completed_by_employee_id')) {
            $payload['completed_by_employee_id'] = $actorId > 0 ? $actorId : null;
        }
        $subTask->update($payload);
        $task = $subTask->employeeTask;
        $this->timeline->recordForTask($task, EmployeeTaskTimeline::EVENT_SUBTASK_COMPLETED, $subTask->name);

        $fresh = $subTask->fresh();
        $this->notifyAdminLegacySubtaskCompleted($fresh);
        $subTask->loadMissing('employeeTask.employee');
        $this->notifyDailyTasksCompletedIfApplicable($subTask->employeeTask?->employee);
        if ($actorId > 0 && $task) {
            app(EmployeeTaskNotificationService::class)->notifyCoAssigneesSubtaskCompleted(
                $task,
                $subTask->name,
                $actorId,
                null
            );
        }

        return $fresh;
    }

    public function completeOccurrenceSubtask(EmployeeTaskOccurrenceSubtask $subTask): EmployeeTaskOccurrenceSubtask
    {
        if (! \App\Support\TaskMediaFiles::hasRequiredProof(
            $subTask->employee_img,
            $subTask->proof_media_type ?? null,
            (bool) $subTask->requires_image
        )) {
            throw new \RuntimeException(__('messages.employee_image_required'));
        }

        $actorId = (int) (auth()->user()?->employee?->id ?? 0);
        $payload = ['status' => 'completed'];
        if (Schema::hasColumn('employee_task_occurrence_subtasks', 'completed_by_employee_id')) {
            $payload['completed_by_employee_id'] = $actorId > 0 ? $actorId : null;
        }
        $subTask->update($payload);
        $this->timeline->recordForOccurrence(
            $subTask->occurrence,
            EmployeeTaskTimeline::EVENT_SUBTASK_COMPLETED,
            $subTask->name
        );

        $fresh = $subTask->fresh();
        $this->notifyAdminOccurrenceSubtaskCompleted($fresh);
        $subTask->loadMissing('occurrence.employee');
        $this->notifyDailyTasksCompletedIfApplicable($subTask->occurrence?->employee);
        $legacy = $this->legacyTaskForOccurrence($subTask->occurrence);
        if ($actorId > 0 && $legacy) {
            app(EmployeeTaskNotificationService::class)->notifyCoAssigneesSubtaskCompleted(
                $legacy,
                $subTask->name,
                $actorId,
                (int) $subTask->occurrence_id
            );
        }

        return $fresh;
    }

    private function legacyTaskForOccurrence(?EmployeeTaskOccurrence $occurrence): ?EmployeeTask
    {
        if (! $occurrence?->legacy_task_id) {
            return null;
        }

        return EmployeeTask::find($occurrence->legacy_task_id);
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
        if (! \App\Support\TaskMediaFiles::hasRequiredProof(
            $task->employee_img,
            $task->proof_media_type ?? null,
            (bool) $task->is_forced_to_upload_img
        )) {
            throw new \RuntimeException(__('messages.employee_image_required'));
        }
    }

    private function assertOccurrenceProofIfRequired(EmployeeTaskOccurrence $occurrence): void
    {
        if (! \App\Support\TaskMediaFiles::hasRequiredProof(
            $occurrence->employee_img,
            $occurrence->proof_media_type ?? null,
            (bool) $occurrence->is_forced_to_upload_img
        )) {
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
        if (! Schema::hasColumn('sub_employee_tasks', 'bonus_points')) {
            return 0;
        }

        return (int) $task->subTasks()->where('status', 'completed')->sum('bonus_points');
    }

    private function resolvePointsRecipient(
        ?EmployeeTask $task,
        ?EmployeeTaskOccurrence $occurrence
    ): ?EmployeeDetail {
        $recipientId = (int) (
            $occurrence?->completed_by_employee_id
            ?? $task?->completed_by_employee_id
            ?? $occurrence?->employee_id
            ?? $task?->employee_id
            ?? 0
        );
        if ($recipientId < 1) {
            return $task?->employee ?? $occurrence?->employee;
        }

        return EmployeeDetail::find($recipientId) ?? $task?->employee ?? $occurrence?->employee;
    }

    private function awardCompletionPointsForTask(EmployeeTask $task): void
    {
        $recipient = $this->resolvePointsRecipient($task, null);
        if (! $recipient) {
            return;
        }

        $taskPoints = (int) $task->points;
        $bonus = $this->calculateSubtaskBonus($task);
        $this->creditEmployeeTaskPoints(
            $recipient,
            $taskPoints + $bonus,
            $task->name,
            (int) $task->id
        );
    }

    private function awardCompletionPointsForOccurrence(EmployeeTaskOccurrence $occurrence): void
    {
        $recipient = $this->resolvePointsRecipient(null, $occurrence);
        if (! $recipient) {
            return;
        }

        $taskPoints = (int) $occurrence->points;
        $bonus = (int) $occurrence->subtasks()->where('status', 'completed')->sum('bonus_points');
        $this->creditEmployeeTaskPoints(
            $recipient,
            $taskPoints + $bonus,
            $occurrence->name,
            (int) ($occurrence->legacy_task_id ?? $occurrence->id)
        );
    }

    private function creditEmployeeTaskPoints(
        EmployeeDetail $recipient,
        int $total,
        string $taskName,
        int $taskRefId
    ): void {
        if ($total < 1) {
            return;
        }

        $recipient->refresh();
        $recipient->update(['points' => (int) $recipient->points + $total]);

        try {
            $category = EmployeePointCategory::query()
                ->where('code', 'extra_tasks')
                ->where('is_active', true)
                ->first();

            $payload = [
                'points' => $total,
                'source' => EmployeePointsLog::SOURCE_EMPLOYEE_TASK,
                'reason' => 'نقاط إتمام المهمة: '.$taskName,
                'notes' => 'employee_task_id:'.$taskRefId,
            ];

            if ($category) {
                app(EmployeePointsService::class)->applyCategoryMutation(
                    $recipient->id,
                    $category,
                    $payload
                );
            } else {
                $payload['category'] = 'extra_tasks';
                app(EmployeePointsService::class)->addPoints($recipient->id, $payload);
            }
        } catch (\Throwable $e) {
            Log::error('employee_task.points_log_failed', [
                'employee_id' => $recipient->id,
                'points' => $total,
                'task_ref' => $taskRefId,
                'message' => $e->getMessage(),
            ]);
        }
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
