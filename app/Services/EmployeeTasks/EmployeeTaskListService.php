<?php

namespace App\Services\EmployeeTasks;

use App\Enums\EmployeeTaskStatus;
use App\Models\EmployeeTask;
use App\Models\EmployeeTaskOccurrence;
use App\Support\TaskProofMediaType;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class EmployeeTaskListService
{
    public function formatLegacyTask(EmployeeTask $task, callable $photoResolver): array
    {
        $subTotal = $task->subTasks_count ?? $task->subTasks()->count();
        $subDone = $task->subtasks_completed_count
            ?? $task->subTasks()->where('status', 'completed')->count();

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
            'progress' => $subTotal > 0 ? round(($subDone / $subTotal) * 100) : ($task->status === 'completed' ? 100 : 0),
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
        $subTotal = $task->subtasks_count ?? $task->subtasks()->count();
        $subDone = $task->subtasks_completed_count
            ?? $task->subtasks()->where('status', 'completed')->count();

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
            'progress' => $subTotal > 0 ? round(($subDone / $subTotal) * 100) : ($task->status === 'completed' ? 100 : 0),
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
            ->where('status', EmployeeTaskStatus::Completed->value)
            ->where('is_canceled', 0)
            ->whereNull('occurrence_id')
            ->get()
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
