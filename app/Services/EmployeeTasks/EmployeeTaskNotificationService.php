<?php

namespace App\Services\EmployeeTasks;

use App\Models\EmployeeDetail;
use App\Models\EmployeeTask;
use App\Models\EmployeeTaskOccurrence;
use App\Services\EmployeeNotificationService;
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

        $task->loadMissing('employee.user');
        if (! $task->employee) {
            return;
        }

        $this->send($task->employee, $task->name, $task->id, null);
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

    private function send(
        EmployeeDetail $employee,
        string $taskName,
        ?int $legacyTaskId,
        ?int $occurrenceId
    ): void {
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
    }
}
