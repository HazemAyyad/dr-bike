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

        $payload = [
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
            'subtask_names' => $this->legacySubtaskNames($task),
        ];

        return $this->appendAssigneeFields($payload, $task, $photoResolver);
    }

    public function formatOccurrence(EmployeeTaskOccurrence $task, callable $photoResolver): array
    {
        $subTotal = (int) ($task->subtasks_count ?? $task->subtasks()->count());
        $subDone = (int) ($task->subtasks_completed_count
            ?? $task->subtasks()->where('status', 'completed')->count());
        $progress = $this->calcProgressPercent($task, $subTotal, $subDone);

        $payload = [
            'task_id' => $task->legacy_task_id ?? $task->id,
            'occurrence_id' => $task->id,
            'task_name' => $task->name,
            'employee_id' => $task->employee_id,
            'employee_name' => $task->employee->user->name ?? 'unknown',
            'employee_photo' => $photoResolver($task->employee),
            'start_time' => EmployeeVisibleTasks::localDateTimeString($task->start_time),
            'end_time' => EmployeeVisibleTasks::localDateTimeString($task->end_time),
            'scheduled_date' => $task->scheduled_date
                ? Carbon::parse($task->scheduled_date)->toDateString()
                : null,
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
            'task_recurrence' => $task->template?->recurrence_type ?? 'noRepeat',
            'source' => 'occurrence',
            'subtask_names' => $this->occurrenceSubtaskNames($task),
        ];

        $legacyTask = $task->relationLoaded('legacyTask')
            ? $task->legacyTask
            : ($task->legacy_task_id ? EmployeeTask::find($task->legacy_task_id) : null);

        return $this->appendAssigneeFields($payload, $legacyTask, $photoResolver);
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

        if (Schema::hasTable('employee_task_templates')) {
            app(EmployeeTaskRecurrenceService::class)->ensureActiveTemplateOccurrences();
        }

        $legacy = EmployeeTask::with(['employee.user', 'subTasks:id,employee_task_id,name'])
            ->withCount([
                'subTasks',
                'subTasks as subtasks_completed_count' => fn ($q) => $q->where('status', 'completed'),
            ])
            ->whereIn('status', $statuses)
            ->where('is_canceled', 0)
            ->whereNull('occurrence_id')
            ->where(fn ($q) => $this->applyAdminLegacyVisibilityScope($q))
            ->get()
            ->filter(fn ($task) => $this->passesRecurrenceFilter($task))
            ->map(fn ($task) => $this->formatLegacyTask($task, $photoResolver));

        if (! Schema::hasTable('employee_task_occurrences')) {
            return $legacy->values();
        }

        $occurrences = EmployeeTaskOccurrence::with([
            'employee.user',
            'template',
            'legacyTask',
            'subtasks:id,occurrence_id,name',
        ])
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
        $legacy = EmployeeTask::with(['employee.user', 'subTasks:id,employee_task_id,name'])
            ->withCount([
                'subTasks',
                'subTasks as subtasks_completed_count' => fn ($q) => $q->where('status', 'completed'),
            ])
            ->where('status', EmployeeTaskStatus::Completed->value)
            ->where('is_canceled', 0)
            ->whereNull('occurrence_id')
            ->where(fn ($q) => $this->applyAdminLegacyVisibilityScope($q))
            ->get()
            ->map(fn ($task) => $this->formatLegacyTask($task, $photoResolver));

        if (! Schema::hasTable('employee_task_occurrences')) {
            return $legacy->values();
        }

        $occurrences = EmployeeTaskOccurrence::with([
            'employee.user',
            'template',
            'legacyTask',
            'subtasks:id,occurrence_id,name',
        ])
            ->where('status', EmployeeTaskStatus::Completed->value)
            ->where('is_canceled', 0)
            ->get()
            ->map(fn ($task) => $this->formatOccurrence($task, $photoResolver));

        return $legacy->merge($occurrences)->values();
    }

    public function getCanceledItems(callable $photoResolver): Collection
    {
        $legacy = EmployeeTask::with(['employee.user', 'subTasks:id,employee_task_id,name'])
            ->where('is_canceled', 1)
            ->whereNull('parent_id')
            ->get()
            ->map(fn ($task) => $this->formatLegacyTask($task, $photoResolver));

        if (! Schema::hasTable('employee_task_occurrences')) {
            return $legacy->values();
        }

        $occurrences = EmployeeTaskOccurrence::with([
            'employee.user',
            'template',
            'legacyTask',
            'subtasks:id,occurrence_id,name',
        ])
            ->where('is_canceled', 1)
            ->get()
            ->map(fn ($task) => $this->formatOccurrence($task, $photoResolver));

        return $legacy->merge($occurrences)->values();
    }

    private function passesRecurrenceFilter(EmployeeTask $task): bool
    {
        // Always show tasks the employee has already acted on (in progress,
        // overdue, submitted for review, or completed) so admins can review
        // them even when the task is late or its recurrence day is not today.
        if (EmployeeTaskStatus::normalize($task->status) !== EmployeeTaskStatus::Pending) {
            return true;
        }
        if (! empty($task->submitted_at) || (int) ($task->completed_by_employee_id ?? 0) > 0) {
            return true;
        }

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

    /**
     * Skip pre-generated pending legacy copies; the app expands parents per day.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<EmployeeTask>  $query
     */
    private function applyAdminLegacyVisibilityScope($query): void
    {
        $today = Carbon::now()->timezone(EmployeeVisibleTasks::TIMEZONE)->startOfDay();
        $min = $today->copy()->subDays(EmployeeVisibleTasks::OCCURRENCE_VISIBILITY_DAYS_BACK);
        $max = $today->copy()->addDays(EmployeeVisibleTasks::OCCURRENCE_VISIBILITY_DAYS_FORWARD);

        $query->where(function ($q) use ($min, $max) {
            $q->whereNull('parent_id')
                ->orWhereIn('status', [
                    EmployeeTaskStatus::InProgress->value,
                    EmployeeTaskStatus::Overdue->value,
                    'started',
                ])
                ->orWhere(function ($q2) {
                    $q2->whereIn('status', [
                        EmployeeTaskStatus::Completed->value,
                        EmployeeTaskStatus::WaitingReview->value,
                    ])->where(function ($q3) {
                        $q3->where(function ($q4) {
                            $q4->whereNotNull('completed_by_employee_id')
                                ->where('completed_by_employee_id', '>', 0);
                        })->orWhereNotNull('submitted_at');
                    });
                })
                ->orWhere(function ($q5) use ($min, $max) {
                    $q5->where('status', EmployeeTaskStatus::Pending->value)
                        ->whereNotNull('parent_id')
                        ->whereDate('start_time', '>=', $min->toDateString())
                        ->whereDate('start_time', '<=', $max->toDateString());
                });
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function appendAssigneeFields(
        array $payload,
        ?EmployeeTask $legacyTask,
        callable $photoResolver
    ): array {
        $assigneeService = app(EmployeeTaskAssigneeService::class);

        if ($legacyTask instanceof EmployeeTask) {
            $payload['assignee_ids'] = $assigneeService->idsForTask($legacyTask);
            $payload['assignees'] = $assigneeService->profilesForTask($legacyTask, $photoResolver);
        } else {
            $employeeId = (int) ($payload['employee_id'] ?? 0);
            $payload['assignee_ids'] = $employeeId > 0 ? [$employeeId] : [];
            $payload['assignees'] = $employeeId > 0
                ? [[
                    'id' => $employeeId,
                    'name' => (string) ($payload['employee_name'] ?? ''),
                    'photo' => (string) ($payload['employee_photo'] ?? ''),
                ]]
                : [];
        }

        $payload['is_shared'] = count($payload['assignee_ids']) > 1;
        $payload['assignee_label'] = collect($payload['assignees'])
            ->pluck('name')
            ->filter(fn ($name) => is_string($name) && trim($name) !== '')
            ->implode(' · ');

        return $payload;
    }

    /**
     * @return array<int, string>
     */
    private function legacySubtaskNames(EmployeeTask $task): array
    {
        if ($task->relationLoaded('subTasks')) {
            return $task->subTasks
                ->pluck('name')
                ->filter(fn ($name) => is_string($name) && trim($name) !== '')
                ->values()
                ->all();
        }

        return EmployeeSubTask::query()
            ->forLegacyTask($task)
            ->pluck('name')
            ->filter(fn ($name) => is_string($name) && trim($name) !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function occurrenceSubtaskNames(EmployeeTaskOccurrence $occurrence): array
    {
        if ($occurrence->relationLoaded('subtasks')) {
            return $occurrence->subtasks
                ->pluck('name')
                ->filter(fn ($name) => is_string($name) && trim($name) !== '')
                ->values()
                ->all();
        }

        return $occurrence->subtasks()
            ->pluck('name')
            ->filter(fn ($name) => is_string($name) && trim($name) !== '')
            ->values()
            ->all();
    }
}
