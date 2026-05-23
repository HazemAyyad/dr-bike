<?php

namespace App\Services\EmployeeTasks;

use App\Models\EmployeeDetail;
use App\Models\EmployeeTask;
use App\Models\EmployeeTaskOccurrence;
use App\Services\EmployeeNotificationService;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;

class EmployeeTaskNotificationService
{
    public function __construct(
        private readonly EmployeeNotificationService $notifications
    ) {}

    /**
     * @param  array<int>  $employeeIds
     */
    public function notifyAssignedToEmployeeIds(
        EmployeeTask $task,
        array $employeeIds,
        ?int $occurrenceId = null
    ): void {
        if ($task->not_shown_for_employee) {
            return;
        }

        $ids = collect($employeeIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            $ids = [(int) $task->employee_id];
        }

        $notified = [];
        foreach ($ids as $employeeId) {
            if (isset($notified[$employeeId])) {
                continue;
            }
            $employee = EmployeeDetail::with('user')->find($employeeId);
            if (! $employee) {
                continue;
            }
            $this->send($employee, $task->name, $task->id, $occurrenceId);
            $notified[$employeeId] = true;
        }
    }

    public function notifyAssignedLegacy(EmployeeTask $task): void
    {
        $assigneeService = app(EmployeeTaskAssigneeService::class);
        $this->notifyAssignedToEmployeeIds(
            $task,
            $assigneeService->idsForTask($task),
            $task->occurrence_id
        );
    }

    public function notifyAssignedOccurrence(EmployeeTaskOccurrence $occurrence): void
    {
        if ($occurrence->not_shown_for_employee) {
            return;
        }

        $assigneeService = app(EmployeeTaskAssigneeService::class);
        $ids = [];

        if ($occurrence->legacy_task_id) {
            $legacy = EmployeeTask::find($occurrence->legacy_task_id);
            if ($legacy) {
                $ids = $assigneeService->idsForTask($legacy);
            }
        }

        if ($ids === []) {
            $ids = [(int) $occurrence->employee_id];
        }

        $notified = [];
        foreach ($ids as $employeeId) {
            if (isset($notified[$employeeId])) {
                continue;
            }
            $employee = EmployeeDetail::with('user')->find($employeeId);
            if (! $employee) {
                continue;
            }
            $this->send(
                $employee,
                $occurrence->name,
                $occurrence->legacy_task_id,
                $occurrence->id
            );
            $notified[$employeeId] = true;
        }
    }

    public function notifyCoAssigneesSubtaskCompleted(
        EmployeeTask $task,
        string $subtaskName,
        int $actorEmployeeId,
        ?int $occurrenceId = null
    ): void {
        $this->notifyCoAssignees(
            $task,
            $actorEmployeeId,
            'employee_task_co_subtask_done',
            __('messages.employee_task_co_subtask_done_title'),
            __('messages.employee_task_co_subtask_done_body', [
                'actor' => $this->actorName($actorEmployeeId),
                'subtask' => $subtaskName,
                'task' => $task->name,
            ]),
            $occurrenceId
        );
    }

    public function notifyCoAssigneesMainTaskSubmitted(
        EmployeeTask $task,
        int $actorEmployeeId,
        ?int $occurrenceId = null
    ): void {
        $this->notifyCoAssignees(
            $task,
            $actorEmployeeId,
            'employee_task_co_main_done',
            __('messages.employee_task_co_main_done_title'),
            __('messages.employee_task_co_main_done_body', [
                'actor' => $this->actorName($actorEmployeeId),
                'task' => $task->name,
            ]),
            $occurrenceId
        );
    }

    public function notifyCoAssigneesMainTaskCompleted(
        EmployeeTask $task,
        int $actorEmployeeId,
        ?int $occurrenceId = null
    ): void {
        $this->notifyCoAssignees(
            $task,
            $actorEmployeeId,
            'employee_task_co_main_completed',
            __('messages.employee_task_co_main_completed_title'),
            __('messages.employee_task_co_main_completed_body', [
                'actor' => $this->actorName($actorEmployeeId),
                'task' => $task->name,
            ]),
            $occurrenceId
        );
    }

    public function notifyTemplateCreated(int $employeeId, string $taskName, bool $hidden): void
    {
        if ($hidden) {
            return;
        }

        $employee = EmployeeDetail::with('user')->find($employeeId);
        if (! $employee) {
            return;
        }

        $this->send($employee, $taskName, null, null);
    }

    public function notifyTaskApproved(
        EmployeeDetail $employee,
        string $taskName,
        ?int $legacyTaskId,
        ?int $occurrenceId
    ): void {
        $this->withArabicLocale(function () use ($employee, $taskName, $legacyTaskId, $occurrenceId) {
            try {
                $this->notifications->create(
                    $employee,
                    'employee_task_approved',
                    __('messages.employee_task_approved_title'),
                    __('messages.employee_task_approved_body', ['name' => $taskName]),
                    array_filter([
                        'task_id' => $legacyTaskId ? (string) $legacyTaskId : '',
                        'occurrence_id' => $occurrenceId ? (string) $occurrenceId : '',
                        'task_name' => $taskName,
                    ]),
                    $occurrenceId ? 'employee_task_occurrence' : 'employee_task',
                    $occurrenceId ?? $legacyTaskId,
                    true
                );
            } catch (\Throwable $e) {
                Log::error('Employee task approved notification failed: '.$e->getMessage(), [
                    'employee_id' => $employee->id,
                ]);
            }
        });
    }

    public function notifyTaskRejected(
        EmployeeDetail $employee,
        string $taskName,
        string $rejectionNotes,
        ?int $legacyTaskId,
        ?int $occurrenceId
    ): void {
        $this->withArabicLocale(function () use ($employee, $taskName, $rejectionNotes, $legacyTaskId, $occurrenceId) {
            try {
                $this->notifications->create(
                    $employee,
                    'employee_task_rejected',
                    __('messages.employee_task_rejected_title'),
                    __('messages.employee_task_rejected_body', [
                        'name' => $taskName,
                        'notes' => $rejectionNotes,
                    ]),
                    array_filter([
                        'task_id' => $legacyTaskId ? (string) $legacyTaskId : '',
                        'occurrence_id' => $occurrenceId ? (string) $occurrenceId : '',
                        'task_name' => $taskName,
                        'rejection_notes' => $rejectionNotes,
                    ]),
                    $occurrenceId ? 'employee_task_occurrence' : 'employee_task',
                    $occurrenceId ?? $legacyTaskId,
                    true
                );
            } catch (\Throwable $e) {
                Log::error('Employee task rejected notification failed: '.$e->getMessage(), [
                    'employee_id' => $employee->id,
                ]);
            }
        });
    }

    private function notifyCoAssignees(
        EmployeeTask $task,
        int $actorEmployeeId,
        string $type,
        string $title,
        string $body,
        ?int $occurrenceId = null
    ): void {
        if ($task->not_shown_for_employee || $actorEmployeeId <= 0) {
            return;
        }

        $assigneeIds = app(EmployeeTaskAssigneeService::class)->idsForTask($task);
        if (count($assigneeIds) <= 1) {
            return;
        }

        $this->withArabicLocale(function () use ($task, $actorEmployeeId, $type, $title, $body, $occurrenceId, $assigneeIds) {
            foreach ($assigneeIds as $employeeId) {
                if ((int) $employeeId === $actorEmployeeId) {
                    continue;
                }
                $employee = EmployeeDetail::with('user')->find($employeeId);
                if (! $employee) {
                    continue;
                }
                try {
                    $this->notifications->create(
                        $employee,
                        $type,
                        $title,
                        $body,
                        array_filter([
                            'task_id' => (string) $task->id,
                            'occurrence_id' => $occurrenceId ? (string) $occurrenceId : '',
                            'task_name' => $task->name,
                            'actor_employee_id' => (string) $actorEmployeeId,
                        ]),
                        $occurrenceId ? 'employee_task_occurrence' : 'employee_task',
                        $occurrenceId ?? $task->id,
                        true
                    );
                } catch (\Throwable $e) {
                    Log::error('Co-assignee task notification failed: '.$e->getMessage(), [
                        'employee_id' => $employeeId,
                        'type' => $type,
                    ]);
                }
            }
        });
    }

    private function actorName(int $actorEmployeeId): string
    {
        $actor = EmployeeDetail::with('user')->find($actorEmployeeId);

        return $actor?->user?->name ?? __('messages.employee');
    }

    private function send(
        EmployeeDetail $employee,
        string $taskName,
        ?int $legacyTaskId,
        ?int $occurrenceId
    ): void {
        $this->withArabicLocale(function () use ($employee, $taskName, $legacyTaskId, $occurrenceId) {
            try {
                $title = __('messages.employee_task_assigned_title');
                $body = __('messages.employee_task_assigned_body', ['name' => $taskName]);

                $this->notifications->create(
                    $employee,
                    'employee_task_assigned',
                    $title,
                    $body,
                    array_filter([
                        'task_id' => $legacyTaskId ? (string) $legacyTaskId : '',
                        'occurrence_id' => $occurrenceId ? (string) $occurrenceId : '',
                        'task_name' => $taskName,
                    ]),
                    $occurrenceId ? 'employee_task_occurrence' : 'employee_task',
                    $occurrenceId ?? $legacyTaskId,
                    true
                );
            } catch (\Throwable $e) {
                Log::error('Employee task assigned notification failed: '.$e->getMessage(), [
                    'employee_id' => $employee->id,
                    'task_name' => $taskName,
                ]);
            }
        });
    }

    /**
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    private function withArabicLocale(callable $callback): mixed
    {
        $previous = App::getLocale();
        App::setLocale('ar');

        try {
            return $callback();
        } finally {
            App::setLocale($previous);
        }
    }
}
