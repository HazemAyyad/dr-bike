<?php

namespace App\Services\EmployeeTasks;

use App\Enums\EmployeeTaskStatus;
use App\Models\EmployeeSubTask;
use App\Models\EmployeeTask;
use App\Models\EmployeeTaskOccurrence;
use App\Support\EmployeeVisibleTasks;
use App\Support\TaskProofMediaType;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class EmployeeTaskListService
{
    public function formatLegacyTask(EmployeeTask $task, callable $photoResolver): array
    {
        [$subTotal, $subDone, $progress] = $this->legacySubtaskProgress($task);

        return [
            'task_id' => $task->id,
            'occurrence_id' => null,
            'task_name' => $task->name,
            'employee_id' => $task->employee_id,
            'employee_name' => $task->employee->user->name ?? 'unknown',
            'employee_photo' => $photoResolver($task->employee),
            'start_time' => $task->start_time,
            'end_time' => $task->end_time,
            'status' => EmployeeTaskStatus::normalize($task->status)->value,
            'priority' => $task->priority ?? 'medium',
            'points' => (int) ($task->points ?? 0),
            'progress' => $progress,
            'is_canceled' => (bool) $task->is_canceled,
            'is_forced_to_upload_img' => (bool) $task->is_forced_to_upload_img,
            'proof_media_type' => TaskProofMediaType::normalize($task->proof_media_type ?? null, (bool) $task->is_forced_to_upload_img),
            'employee_img' => $task->employee_img
                ? 'public/EmployeeTasksImages/'.$task->employee_img[0]
                : 'no employee image',
            'admin_img' => (is_array($task->admin_img) && count($task->admin_img) > 0)
                ? 'public/AdminEmployeeTasksImages/'.$task->admin_img[0]
                : 'no admin image',
            'audio' => $task->audio
                ? 'public/employeeTasksAudio/'.$task->audio
                : 'no audio',
            'parent_id' => $task->parent_id,
            'task_recurrence' => $task->task_recurrence ?? 'noRepeat',
            'task_recurrence_time' => is_array($task->task_recurrence_time)
                ? $task->task_recurrence_time
                : [],
            'source' => 'legacy',
        ];
    }

    public function formatOccurrence(EmployeeTaskOccurrence $task, callable $photoResolver): array
    {
        $subTotal = (int) ($task->subtasks_count ?? $task->subtasks()->count());
        $subDone = (int) ($task->subtasks_completed_count
            ?? $task->subtasks()->where('status', 'completed')->count());
        $progress = $this->calcProgressPercent($task, $subTotal, $subDone);

        return [
            'task_id' => $task->legacy_task_id ?? $task->id,
            'occurrence_id' => $task->id,
            'task_name' => $task->name,
            'employee_id' => $task->employee_id,
            'employee_name' => $task->employee->user->name ?? 'unknown',
            'employee_photo' => $photoResolver($task->employee),
            'start_time' => $task->start_time,
            'end_time' => $task->end_time,
            'status' => EmployeeTaskStatus::normalize($task->status)->value,
            'priority' => $task->priority ?? 'medium',
            'points' => (int) ($task->points ?? 0),
            'progress' => $progress,
            'is_canceled' => (bool) $task->is_canceled,
            'is_forced_to_upload_img' => (bool) $task->is_forced_to_upload_img,
            'proof_media_type' => TaskProofMediaType::normalize($task->proof_media_type ?? null, (bool) $task->is_forced_to_upload_img),
            'employee_img' => $task->employee_img
                ? 'public/EmployeeTasksImages/'.$task->employee_img[0]
                : 'no employee image',
            'admin_img' => (is_array($task->admin_img) && count($task->admin_img) > 0)
                ? 'public/AdminEmployeeTasksImages/'.$task->admin_img[0]
                : 'no admin image',
            'audio' => $task->audio
                ? 'public/employeeTasksAudio/'.$task->audio
                : 'no audio',
            'parent_id' => null,
            'template_id' => $task->template_id,
            'source' => 'occurrence',
        ];
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function legacySubtaskProgress(EmployeeTask $task): array
    {
        if (isset($task->subTasks_count, $task->subtasks_completed_count)) {
            $subTotal = (int) $task->subTasks_count;
            $subDone = (int) $task->subtasks_completed_count;
        } else {
            $base = EmployeeSubTask::query()->forLegacyTask($task);
            $subTotal = (int) (clone $base)->count();
            $subDone = (int) (clone $base)->where('status', 'completed')->count();
        }

        return [$subTotal, $subDone, $this->calcProgressPercent($task, $subTotal, $subDone)];
    }

    private function calcProgressPercent(
        EmployeeTask|EmployeeTaskOccurrence $task,
        int $subTotal,
        int $subDone
    ): int {
        if ($subTotal > 0) {
            if ($subDone >= $subTotal) {
                return 100;
            }

            return (int) round(($subDone / $subTotal) * 100);
        }

        return EmployeeTaskStatus::normalize($task->status) === EmployeeTaskStatus::Completed
            ? 100
            : 0;
    }

    public function getOngoingItems(callable $photoResolver): Collection
    {
        $statuses = EmployeeTaskStatus::ongoingTabValues();

        $legacy = EmployeeTask::with('employee.user')
            ->withCount([
                'subTasks',
                'subTasks as subtasks_completed_count' => fn ($q) => $q->where('status', 'completed'),
            ])
            ->whereIn('status', $statuses)
            ->where('is_canceled', 0)
            ->whereNull('occurrence_id')
            ->get()
            ->filter(fn ($task) => EmployeeVisibleTasks::includeLegacyRowInEmployeePayload($task))
            ->filter(fn ($task) => $this->passesRecurrenceFilter($task))
            ->map(fn ($task) => $this->formatLegacyTask($task, $photoResolver));

        if (! Schema::hasTable('employee_task_occurrences')) {
            return $legacy->values();
        }

        $occurrences = EmployeeTaskOccurrence::with('employee.user')
            ->withCount([
                'subtasks',
                'subtasks as subtasks_completed_count' => fn ($q) => $q->where('status', 'completed'),
            ])
            ->whereIn('status', $statuses)
            ->where('is_canceled', 0)
            ->get()
            ->map(fn ($task) => $this->formatOccurrence($task, $photoResolver));

        return $legacy->merge($occurrences)->values();
    }

    public function getCompletedItems(callable $photoResolver): Collection
    {
        $legacy = EmployeeTask::with('employee.user')
            ->withCount([
                'subTasks',
                'subTasks as subtasks_completed_count' => fn ($q) => $q->where('status', 'completed'),
            ])
            ->where('status', EmployeeTaskStatus::Completed->value)
            ->where('is_canceled', 0)
            ->whereNull('occurrence_id')
            ->get()
            ->filter(fn ($task) => EmployeeVisibleTasks::includeLegacyRowInEmployeePayload($task))
            ->map(fn ($task) => $this->formatLegacyTask($task, $photoResolver));

        if (! Schema::hasTable('employee_task_occurrences')) {
            return $legacy->values();
        }

        $occurrences = EmployeeTaskOccurrence::with('employee.user')
            ->where('status', EmployeeTaskStatus::Completed->value)
            ->where('is_canceled', 0)
            ->get()
            ->map(fn ($task) => $this->formatOccurrence($task, $photoResolver));

        return $legacy->merge($occurrences)->values();
    }

    public function getCanceledItems(callable $photoResolver): Collection
    {
        $legacy = EmployeeTask::with('employee.user')
            ->where('is_canceled', 1)
            ->get()
            ->filter(fn ($task) => EmployeeVisibleTasks::includeLegacyRowInEmployeePayload($task))
            ->map(fn ($task) => $this->formatLegacyTask($task, $photoResolver));

        if (! Schema::hasTable('employee_task_occurrences')) {
            return $legacy->values();
        }

        $occurrences = EmployeeTaskOccurrence::with('employee.user')
            ->where('is_canceled', 1)
            ->get()
            ->map(fn ($task) => $this->formatOccurrence($task, $photoResolver));

        return $legacy->merge($occurrences)->values();
    }

    private function passesRecurrenceFilter(EmployeeTask $task): bool
    {
        $recurrence = $task->task_recurrence;
        $times = is_array($task->task_recurrence_time) ? $task->task_recurrence_time : [];
        $dayName = strtolower(Carbon::parse($task->start_time)->format('l'));
        $dayOfMonth = (int) Carbon::parse($task->start_time)->format('d');

        return match ($recurrence) {
            'noRepeat', 'daily' => true,
            'weekly' => in_array($dayName, $times),
            'monthly' => in_array((string) $dayOfMonth, $times),
            default => true,
        };
    }
}
