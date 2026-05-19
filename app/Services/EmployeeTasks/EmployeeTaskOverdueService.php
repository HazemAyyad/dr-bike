<?php

namespace App\Services\EmployeeTasks;

use App\Enums\EmployeeTaskStatus;
use App\Models\EmployeeTask;
use App\Models\EmployeeTaskOccurrence;
use App\Models\EmployeeTaskTimeline;
use App\Services\EmployeeNotificationService;
use Illuminate\Support\Facades\Log;

class EmployeeTaskOverdueService
{
    public function __construct(
        private readonly EmployeeTaskTimelineService $timeline,
        private readonly EmployeeNotificationService $employeeNotifications
    ) {}

    public function markOverdueTasks(): int
    {
        $count = 0;

        $legacy = EmployeeTask::query()
            ->where('is_canceled', 0)
            ->whereIn('status', [
                EmployeeTaskStatus::Pending->value,
                EmployeeTaskStatus::InProgress->value,
                EmployeeTaskStatus::Ongoing->value,
            ])
            ->where('end_time', '<', now())
            ->get();

        foreach ($legacy as $task) {
            $task->update(['status' => EmployeeTaskStatus::Overdue->value]);
            $this->timeline->recordForTask($task, EmployeeTaskTimeline::EVENT_OVERDUE);
            $this->notifyEmployee($task->employee_id, $task->name);
            $count++;
        }

        if (\Illuminate\Support\Facades\Schema::hasTable('employee_task_occurrences')) {
            $occurrences = EmployeeTaskOccurrence::query()
                ->where('is_canceled', 0)
                ->whereIn('status', [
                    EmployeeTaskStatus::Pending->value,
                    EmployeeTaskStatus::InProgress->value,
                ])
                ->where('end_time', '<', now())
                ->get();

            foreach ($occurrences as $occurrence) {
                $occurrence->update(['status' => EmployeeTaskStatus::Overdue->value]);
                $this->timeline->recordForOccurrence($occurrence, EmployeeTaskTimeline::EVENT_OVERDUE);
                $this->notifyEmployee($occurrence->employee_id, $occurrence->name);
                $count++;
            }
        }

        return $count;
    }

    private function notifyEmployee(int $employeeId, string $taskName): void
    {
        try {
            if (method_exists($this->employeeNotifications, 'notifyTaskOverdue')) {
                $this->employeeNotifications->notifyTaskOverdue($employeeId, $taskName);
            }
        } catch (\Throwable $e) {
            Log::error('Overdue task notification failed: '.$e->getMessage());
        }
    }
}
