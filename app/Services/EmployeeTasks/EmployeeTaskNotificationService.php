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

    public function notifyAssignedLegacy(EmployeeTask $task): void
    {
        if ($task->not_shown_for_employee) {
            return;
        }

        $assigneeService = app(EmployeeTaskAssigneeService::class);
        $ids = $assigneeService->idsForTask($task);
        $notified = [];

        foreach ($ids as $employeeId) {
            if (isset($notified[$employeeId])) {
                continue;
            }
            $employee = EmployeeDetail::with('user')->find($employeeId);
            if (! $employee) {
                continue;
            }
            $this->send($employee, $task->name, $task->id, null);
            $notified[$employeeId] = true;
        }
    }

    public function notifyAssignedOccurrence(EmployeeTaskOccurrence $occurrence): void
    {
        if ($occurrence->not_shown_for_employee) {
            return;
        }

        $occurrence->loadMissing('employee.user');
        if (! $occurrence->employee) {
            return;
        }

        $this->send(
            $occurrence->employee,
            $occurrence->name,
            $occurrence->legacy_task_id,
            $occurrence->id
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
