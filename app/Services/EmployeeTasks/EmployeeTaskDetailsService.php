<?php

namespace App\Services\EmployeeTasks;

use App\Enums\EmployeeTaskStatus;
use App\Models\EmployeeTask;
use App\Models\EmployeeTaskOccurrence;
use App\Models\EmployeeTaskOccurrenceSubtask;
use App\Models\EmployeeSubTask;
use App\Models\EmployeeTaskTemplate;
use App\Support\TaskReminderConfig;
use Illuminate\Support\Facades\Schema;

class EmployeeTaskDetailsService
{
    public function __construct(
        private readonly EmployeeTaskTimelineService $timeline
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function formatLegacy(EmployeeTask $employeeTask, callable $photoResolver): array
    {
        $employeeTask->loadMissing([
            'subTasks' => fn ($q) => $q->orderBy('sort_order'),
            'subTasks.completedByEmployee.user',
            'employee.user',
            'completedByEmployee.user',
        ]);

        $employeeTask->subTasks->transform(function ($subTask) {
            if ($subTask->admin_img) {
                $subTask->admin_img = collect($subTask->admin_img)
                    ->map(fn ($img) => 'public/EmployeeSubTasks/AdminImages/'.$img)
                    ->toArray();
            }
            if ($subTask->employee_img) {
                $subTask->employee_img = collect($subTask->employee_img)
                    ->map(fn ($img) => 'public/EmployeeSubTasks/EmployeeImages/'.$img)
                    ->toArray();
            }

            return $subTask;
        });

        $employeeTask->makeHidden(['admin_img', 'employee_img', 'audio']);
        $taskData = $employeeTask->toArray();
        $taskData['id'] = $employeeTask->id;
        $taskData['task_id'] = $employeeTask->id;
        $taskData['employee_name'] = $employeeTask->employee?->user?->name ?? '';
        $taskData['employee_photo'] = $photoResolver($employeeTask->employee);
        $taskData['admin_img'] = $this->formatAdminImages($employeeTask->admin_img);
        $taskData['employee_img'] = $this->formatEmployeeImages($employeeTask->employee_img);
        $taskData['audio'] = $employeeTask->audio
            ? 'public/employeeTasksAudio/'.$employeeTask->audio
            : 'no audio';
        $taskData['status'] = EmployeeTaskStatus::normalize($employeeTask->status)->value;
        $taskData['priority'] = $employeeTask->priority ?? 'medium';
        $taskData['requires_admin_review'] = (bool) ($employeeTask->requires_admin_review ?? true);
        $taskData['progress'] = $this->progressFromSubtasks($employeeTask->subTasks, $employeeTask->status);
        $taskData['timeline'] = $this->timeline->listCombined($employeeTask->id, $employeeTask->occurrence_id);
        $taskData['sub_tasks'] = $employeeTask->subTasks
            ->map(fn (EmployeeSubTask $sub) => $this->formatLegacySubtask($sub))
            ->values()
            ->all();
        $assigneeService = app(EmployeeTaskAssigneeService::class);
        $taskData['assignee_ids'] = $assigneeService->idsForTask($employeeTask);
        $taskData['assignees'] = $assigneeService->profilesForTask($employeeTask, $photoResolver);
        $taskData['completed_by_employee_id'] = $employeeTask->completed_by_employee_id;
        $taskData['completed_by_name'] = $employeeTask->completedByEmployee?->user?->name;

        $template = $employeeTask->template_id
            ? EmployeeTaskTemplate::find($employeeTask->template_id)
            : null;

        return $this->enrichWithRecurrenceMeta($taskData, $template, $employeeTask);
    }

    /**
     * @return array<string, mixed>
     */
    public function formatOccurrence(EmployeeTaskOccurrence $occurrence, callable $photoResolver): array
    {
        $occurrence->loadMissing([
            'subtasks' => fn ($q) => $q->orderBy('sort_order'),
            'subtasks.completedByEmployee.user',
            'employee.user',
            'completedByEmployee.user',
            'template',
        ]);

        $subTasks = $occurrence->subtasks
            ->map(fn (EmployeeTaskOccurrenceSubtask $sub) => $this->formatOccurrenceSubtask($sub))
            ->values()
            ->all();

        $subTotal = count($subTasks);
        $subDone = collect($subTasks)->where('status', 'completed')->count();

        $taskData = [
            'id' => $occurrence->id,
            'task_id' => $occurrence->legacy_task_id ?? $occurrence->id,
            'occurrence_id' => $occurrence->id,
            'template_id' => $occurrence->template_id,
            'employee_id' => $occurrence->employee_id,
            'name' => $occurrence->name,
            'description' => $occurrence->description,
            'notes' => $occurrence->notes,
            'points' => (int) $occurrence->points,
            'priority' => $occurrence->priority ?? 'medium',
            'requires_admin_review' => (bool) ($occurrence->requires_admin_review ?? true),
            'status' => EmployeeTaskStatus::normalize($occurrence->status)->value,
            'is_canceled' => (bool) $occurrence->is_canceled,
            'is_forced_to_upload_img' => (bool) $occurrence->is_forced_to_upload_img,
            'not_shown_for_employee' => (bool) $occurrence->not_shown_for_employee,
            'start_time' => $occurrence->start_time,
            'end_time' => $occurrence->end_time,
            'scheduled_date' => $occurrence->scheduled_date,
            'employee_name' => $occurrence->employee?->user?->name ?? '',
            'employee_photo' => $photoResolver($occurrence->employee),
            'admin_img' => $this->formatAdminImages($occurrence->admin_img),
            'employee_img' => $this->formatEmployeeImages($occurrence->employee_img),
            'audio' => $occurrence->audio
                ? 'public/employeeTasksAudio/'.$occurrence->audio
                : 'no audio',
            'rejection_notes' => $occurrence->rejection_notes,
            'employee_notes' => $occurrence->employee_notes,
            'started_at' => $occurrence->started_at,
            'submitted_at' => $occurrence->submitted_at,
            'reviewed_at' => $occurrence->reviewed_at,
            'progress' => $subTotal > 0 ? round(($subDone / $subTotal) * 100) : 0,
            'timeline' => $this->timeline->listCombined($occurrence->legacy_task_id, $occurrence->id),
            'sub_tasks' => $subTasks,
            'task_recurrence' => $occurrence->template?->recurrence_type ?? 'noRepeat',
            'source' => 'occurrence',
            'completed_by_employee_id' => $occurrence->completed_by_employee_id,
            'completed_by_name' => $occurrence->completedByEmployee?->user?->name,
        ];

        $assigneeService = app(EmployeeTaskAssigneeService::class);
        if ($occurrence->legacy_task_id) {
            $legacy = EmployeeTask::find($occurrence->legacy_task_id);
            if ($legacy) {
                $taskData['assignee_ids'] = $assigneeService->idsForTask($legacy);
                $taskData['assignees'] = $assigneeService->profilesForTask($legacy, $photoResolver);
            }
        }
        if (! isset($taskData['assignee_ids'])) {
            $taskData['assignee_ids'] = [(int) $occurrence->employee_id];
        }
        if (! isset($taskData['assignees'])) {
            $taskData['assignees'] = [[
                'id' => (int) $occurrence->employee_id,
                'name' => $occurrence->employee?->user?->name ?? '',
                'photo' => $photoResolver($occurrence->employee),
            ]];
        }

        return $this->enrichWithRecurrenceMeta($taskData, $occurrence->template, null, $occurrence);
    }

    /**
     * @param  array<string, mixed>  $taskData
     * @return array<string, mixed>
     */
    private function enrichWithRecurrenceMeta(
        array $taskData,
        ?EmployeeTaskTemplate $template,
        ?EmployeeTask $legacyTask = null,
        ?EmployeeTaskOccurrence $occurrence = null
    ): array {
        if ($occurrence) {
            $taskData['occurrence_id'] = $occurrence->id;
        }

        if ($template) {
            $taskData['template_id'] = $template->id;
            $taskData['recurrence_config'] = $template->recurrence_config ?? [];
            $taskData['task_recurrence'] = $template->recurrence_type ?? ($taskData['task_recurrence'] ?? 'noRepeat');
        } elseif (! empty($taskData['recurrence_config'])) {
            // already set
        } else {
            $taskData['recurrence_config'] = [];
        }

        $config = is_array($taskData['recurrence_config'] ?? null) ? $taskData['recurrence_config'] : [];

        $taskData = array_merge(
            $taskData,
            TaskReminderConfig::apiReminderFields(
                $config !== [] ? $config : null,
                $legacyTask?->reminder_before_minutes,
                $legacyTask?->reminder_channel
            )
        );

        return $taskData;
    }

    /**
     * @param  mixed  $adminImg
     * @return array<int, string>|string
     */
    private function formatAdminImages($adminImg): array|string
    {
        if (! is_array($adminImg) || count($adminImg) === 0) {
            return 'no images';
        }

        return collect($adminImg)->map(fn ($img) => 'public/AdminEmployeeTasksImages/'.$img)->toArray();
    }

    /**
     * @param  mixed  $employeeImg
     * @return array<int, string>|string
     */
    private function formatEmployeeImages($employeeImg): array|string
    {
        if (! is_array($employeeImg) || count($employeeImg) === 0) {
            return 'no images';
        }

        return collect($employeeImg)->map(fn ($img) => 'public/EmployeeTasksImages/'.$img)->toArray();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, mixed>  $subTasks
     */
    private function progressFromSubtasks($subTasks, ?string $status): int
    {
        $subTotal = $subTasks->count();
        if ($subTotal === 0) {
            return $status === 'completed' ? 100 : 0;
        }
        $subDone = $subTasks->where('status', 'completed')->count();

        return (int) round(($subDone / $subTotal) * 100);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatLegacySubtask(EmployeeSubTask $sub): array
    {
        $adminImg = $sub->admin_img
            ? collect($sub->admin_img)->map(fn ($img) => 'public/EmployeeSubTasks/AdminImages/'.$img)->toArray()
            : [];
        $employeeImg = $sub->employee_img
            ? collect($sub->employee_img)->map(fn ($img) => 'public/EmployeeSubTasks/EmployeeImages/'.$img)->toArray()
            : [];

        return [
            'id' => $sub->id,
            'name' => $sub->name,
            'description' => $sub->description,
            'status' => $sub->status,
            'is_forced_to_upload_img' => (bool) $sub->is_forced_to_upload_img,
            'bonus_points' => (int) ($sub->bonus_points ?? 0),
            'sort_order' => (int) ($sub->sort_order ?? 0),
            'admin_img' => $adminImg,
            'employee_img' => $employeeImg,
            'completed_by_employee_id' => Schema::hasColumn('sub_employee_tasks', 'completed_by_employee_id')
                ? $sub->completed_by_employee_id
                : null,
            'completed_by_name' => $sub->completedByEmployee?->user?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatOccurrenceSubtask(EmployeeTaskOccurrenceSubtask $sub): array
    {
        return [
            'id' => $sub->id,
            'name' => $sub->name,
            'description' => $sub->description,
            'status' => $sub->status,
            'is_forced_to_upload_img' => (bool) $sub->requires_image,
            'bonus_points' => (int) $sub->bonus_points,
            'sort_order' => (int) $sub->sort_order,
            'admin_img' => $sub->admin_img
                ? collect($sub->admin_img)->map(fn ($img) => 'public/EmployeeSubTasks/AdminImages/'.$img)->toArray()
                : [],
            'employee_img' => $sub->employee_img
                ? collect($sub->employee_img)->map(fn ($img) => 'public/EmployeeSubTasks/EmployeeImages/'.$img)->toArray()
                : [],
            'completed_by_employee_id' => Schema::hasColumn('employee_task_occurrence_subtasks', 'completed_by_employee_id')
                ? $sub->completed_by_employee_id
                : null,
            'completed_by_name' => $sub->completedByEmployee?->user?->name,
        ];
    }
}
