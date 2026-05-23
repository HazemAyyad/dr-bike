<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\EmployeeTask;
use App\Models\EmployeeTaskOccurrence;
use App\Models\EmployeeTaskOccurrenceSubtask;
use App\Models\EmployeeTaskTemplate;
use App\Models\EmployeeTaskTemplateSubtask;
use App\Models\Logs;
use App\Models\EmployeeTaskTimeline;
use App\Services\EmployeeTasks\EmployeeTaskPerformanceService;
use App\Services\EmployeeTasks\EmployeeTaskRecurrenceService;
use App\Services\EmployeeTasks\EmployeeTaskTimelineService;
use App\Services\EmployeeTasks\EmployeeTaskWorkflowService;
use App\Services\AdminNotificationService;
use App\Services\EmployeeTasks\EmployeeTaskAssigneeService;
use App\Services\EmployeeTasks\EmployeeTaskNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class EmployeeTaskOperationsController extends Controller
{
    public function __construct(
        private readonly EmployeeTaskWorkflowService $workflow,
        private readonly EmployeeTaskTimelineService $timeline,
        private readonly EmployeeTaskRecurrenceService $recurrence,
        private readonly EmployeeTaskPerformanceService $performance
    ) {}

    public function startTask(Request $request)
    {
        try {
            $request->validate([
                'employee_task_id' => 'nullable|exists:employee_tasks,id',
                'occurrence_id' => 'nullable|exists:employee_task_occurrences,id',
            ]);

            if ($request->occurrence_id) {
                $occurrence = EmployeeTaskOccurrence::findOrFail($request->occurrence_id);
                $this->workflow->startOccurrence($occurrence);

                return response()->json([
                    'status' => 'success',
                    'message' => __('messages.task_started'),
                    'occurrence' => $occurrence->fresh(),
                ], 200);
            }

            $task = EmployeeTask::findOrFail($request->employee_task_id);
            $this->workflow->startTask($task);

            return response()->json([
                'status' => 'success',
                'message' => __('messages.task_started'),
                'employee_task' => $task->fresh(),
            ], 200);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 200);
        }
    }

    public function submitTask(Request $request)
    {
        try {
            $request->validate([
                'employee_task_id' => 'nullable|exists:employee_tasks,id',
                'occurrence_id' => 'nullable|exists:employee_task_occurrences,id',
                'employee_notes' => 'nullable|string',
            ]);

            if ($request->occurrence_id) {
                $occurrence = EmployeeTaskOccurrence::findOrFail($request->occurrence_id);
                $this->workflow->submitOccurrenceForReview($occurrence, $request->employee_notes);

                return response()->json([
                    'status' => 'success',
                    'message' => __('messages.task_submitted_for_review'),
                ], 200);
            }

            $task = EmployeeTask::findOrFail($request->employee_task_id);
            $this->workflow->submitTaskForReview($task, $request->employee_notes);

            return response()->json([
                'status' => 'success',
                'message' => __('messages.task_submitted_for_review'),
            ], 200);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 200);
        }
    }

    public function approveTask(Request $request)
    {
        try {
            $request->validate([
                'employee_task_id' => 'nullable|exists:employee_tasks,id',
                'occurrence_id' => 'nullable|exists:employee_task_occurrences,id',
            ]);

            if ($request->occurrence_id) {
                $occurrence = EmployeeTaskOccurrence::findOrFail($request->occurrence_id);
                $occurrence = $this->workflow->approveOccurrence($occurrence);
                $this->notifyCompleted($occurrence->employee, $occurrence);

                return response()->json(['status' => 'success', 'message' => __('messages.task_completed')], 200);
            }

            $task = EmployeeTask::findOrFail($request->employee_task_id);
            $task = $this->workflow->approveTask($task);
            $this->notifyCompleted($task->employee, $task);

            return response()->json(['status' => 'success', 'message' => __('messages.task_completed')], 200);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 200);
        }
    }

    public function rejectTask(Request $request)
    {
        try {
            $request->validate([
                'employee_task_id' => 'nullable|exists:employee_tasks,id',
                'occurrence_id' => 'nullable|exists:employee_task_occurrences,id',
                'rejection_notes' => 'required|string|max:2000',
            ]);

            if ($request->occurrence_id) {
                $occurrence = EmployeeTaskOccurrence::findOrFail($request->occurrence_id);
                $this->workflow->rejectOccurrence($occurrence, $request->rejection_notes);

                return response()->json(['status' => 'success', 'message' => __('messages.task_rejected')], 200);
            }

            $task = EmployeeTask::findOrFail($request->employee_task_id);
            $this->workflow->rejectTask($task, $request->rejection_notes);

            return response()->json(['status' => 'success', 'message' => __('messages.task_rejected')], 200);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 200);
        }
    }

    public function getTimeline(Request $request)
    {
        $request->validate([
            'employee_task_id' => 'nullable|exists:employee_tasks,id',
            'occurrence_id' => 'nullable|exists:employee_task_occurrences,id',
        ]);

        $events = $this->timeline->listCombined(
            $request->employee_task_id,
            $request->occurrence_id
        );

        return response()->json(['status' => 'success', 'timeline' => $events], 200);
    }

    public function getPerformance(Request $request)
    {
        $request->validate([
            'employee_id' => 'required|exists:employee_details,id',
        ]);

        return response()->json([
            'status' => 'success',
            'performance' => $this->performance->getPerformance((int) $request->employee_id),
        ], 200);
    }

    public function createWithTemplate(Request $request)
    {
        try {
            $data = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'notes' => 'nullable|string',
                'employee_id' => 'required_without:employee_ids|exists:employee_details,id',
                'employee_ids' => 'nullable|array|min:1',
                'employee_ids.*' => 'integer|exists:employee_details,id',
                'points' => 'required|integer|min:0',
                'priority' => 'nullable|in:low,medium,high',
                'start_time' => 'required|date',
                'end_time' => 'required|date|after_or_equal:start_time',
                'task_recurrence' => 'required|in:noRepeat,daily,weekly,monthly,yearly',
                'recurrence_config' => 'nullable|array',
                'not_shown_for_employee' => 'nullable|boolean',
                'is_forced_to_upload_img' => 'nullable|boolean',
                'requires_admin_review' => 'nullable|boolean',
                'sub_employee_tasks' => 'nullable|array',
                'sub_employee_tasks.*.name' => 'required|string|max:255',
                'sub_employee_tasks.*.description' => 'nullable|string',
                'sub_employee_tasks.*.is_forced_to_upload_img' => 'nullable|boolean',
                'sub_employee_tasks.*.bonus_points' => 'nullable|integer|min:0',
                'sub_employee_tasks.*.sort_order' => 'nullable|integer|min:0',
                'reminder_before_minutes' => 'nullable|integer|min:0|max:10080',
                'reminder_channel' => 'nullable|string|in:push,email',
                'reminder_when' => 'nullable|string|in:none,at_time,before_10m,before_1h,before_1d',
            ]);

            $start = \Carbon\Carbon::parse($data['start_time']);
            $end = \Carbon\Carbon::parse($data['end_time']);
            if ($end->lte($start)) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.end_time_must_be_after_start'),
                ], 200);
            }

            $reminderMinutes = \App\Support\TaskReminderConfig::minutesFromRequest($request);
            $reminderChannel = \App\Support\TaskReminderConfig::channelFromRequest($request);

            $recurrenceConfig = \App\Support\TaskReminderConfig::mergeIntoRecurrenceConfig(
                array_merge($data['recurrence_config'] ?? [], [
                    'start_time' => $data['start_time'],
                    'end_time' => $data['end_time'],
                    'anchor_date' => $request->input('recurrence_config.anchor_date', $data['start_time']),
                ]),
                $reminderMinutes,
                $reminderChannel
            );

            $assigneeService = app(EmployeeTaskAssigneeService::class);
            $assigneeIds = $assigneeService->resolveAssigneeIdsFromRequest(
                $request,
                (int) ($data['employee_id'] ?? 0)
            );

            if (count($assigneeIds) > 1) {
                $request->merge(['use_v2_recurrence' => false]);

                return app(EmployeeTasks::class)->createEmployeeTask($request);
            }

            $data['employee_id'] = $assigneeIds[0] ?? (int) $data['employee_id'];

            $template = EmployeeTaskTemplate::create([
                'employee_id' => $data['employee_id'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'notes' => $data['notes'] ?? null,
                'points' => $data['points'],
                'priority' => $data['priority'] ?? 'medium',
                'is_forced_to_upload_img' => $request->boolean('is_forced_to_upload_img'),
                'requires_admin_review' => $request->boolean('requires_admin_review', true),
                'not_shown_for_employee' => $request->boolean('not_shown_for_employee'),
                'recurrence_type' => $data['task_recurrence'],
                'recurrence_config' => $recurrenceConfig,
                'created_by' => auth()->id(),
            ]);

            if ($request->has('sub_employee_tasks')) {
                foreach ($request->sub_employee_tasks as $index => $sub) {
                    EmployeeTaskTemplateSubtask::create([
                        'template_id' => $template->id,
                        'name' => $sub['name'],
                        'description' => $sub['description'] ?? null,
                        'sort_order' => $sub['sort_order'] ?? $index,
                        'requires_image' => (bool) ($sub['is_forced_to_upload_img'] ?? false),
                        'bonus_points' => (int) ($sub['bonus_points'] ?? 0),
                    ]);
                }
            }

            $template->load('subtasks');

            $occurrences = $this->recurrence->ensureOccurrences($template);
            $summary = $this->recurrence->buildRecurrenceSummary($template);
            $notifier = app(EmployeeTaskNotificationService::class);

            $firstOccurrence = $occurrences->first();
            if ($firstOccurrence) {
                $notifier->notifyAssignedOccurrence($firstOccurrence);
            }

            return response()->json([
                'status' => 'success',
                'message' => __('messages.employee_task_created_successfully'),
                'template_id' => $template->id,
                'recurrence_summary' => $summary,
                'occurrences_created' => $occurrences->count(),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 200);
        }
    }

    public function updateWithTemplate(Request $request)
    {
        try {
            $data = $request->validate([
                'template_id' => 'required|exists:employee_task_templates,id',
                'occurrence_id' => 'nullable|exists:employee_task_occurrences,id',
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'notes' => 'nullable|string',
                'employee_id' => 'required_without:employee_ids|exists:employee_details,id',
                'employee_ids' => 'nullable|array|min:1',
                'employee_ids.*' => 'integer|exists:employee_details,id',
                'points' => 'required|integer|min:0',
                'priority' => 'nullable|in:low,medium,high',
                'start_time' => 'required|date',
                'end_time' => 'required|date|after_or_equal:start_time',
                'task_recurrence' => 'required|in:noRepeat,daily,weekly,monthly,yearly',
                'recurrence_config' => 'nullable|array',
                'not_shown_for_employee' => 'nullable|boolean',
                'is_forced_to_upload_img' => 'nullable|boolean',
                'requires_admin_review' => 'nullable|boolean',
                'sub_employee_tasks' => 'nullable|array',
                'sub_employee_tasks.*.id' => 'nullable|integer',
                'sub_employee_tasks.*.name' => 'nullable|string|max:255',
                'sub_employee_tasks.*.description' => 'nullable|string',
                'sub_employee_tasks.*.is_forced_to_upload_img' => 'nullable|in:0,1,true,false',
                'admin_img' => 'nullable|array',
                'admin_img.*' => 'nullable',
                'audio' => 'nullable',
                'reminder_before_minutes' => 'nullable|integer|min:0|max:10080',
                'reminder_channel' => 'nullable|string|in:push,email',
                'reminder_when' => 'nullable|string|in:none,at_time,before_10m,before_1h,before_1d',
            ]);

            $start = \Carbon\Carbon::parse($data['start_time']);
            $end = \Carbon\Carbon::parse($data['end_time']);
            if ($end->lte($start)) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.end_time_must_be_after_start'),
                ], 200);
            }

            $assigneeService = app(EmployeeTaskAssigneeService::class);
            $assigneeIds = $assigneeService->resolveAssigneeIdsFromRequest(
                $request,
                (int) ($data['employee_id'] ?? 0)
            );

            if (count($assigneeIds) > 1) {
                $request->merge(['use_v2_recurrence' => false, 'template_id' => null]);

                return app(EmployeeTasks::class)->updateEmployeeTask($request);
            }

            if ($assigneeIds !== []) {
                $data['employee_id'] = $assigneeIds[0];
            }

            $template = EmployeeTaskTemplate::findOrFail($data['template_id']);
            $reminderMinutes = \App\Support\TaskReminderConfig::minutesFromRequest($request);
            $reminderChannel = \App\Support\TaskReminderConfig::channelFromRequest($request);

            $recurrenceConfig = \App\Support\TaskReminderConfig::mergeIntoRecurrenceConfig(
                array_merge($data['recurrence_config'] ?? [], [
                    'start_time' => $data['start_time'],
                    'end_time' => $data['end_time'],
                    'anchor_date' => $request->input('recurrence_config.anchor_date', $data['start_time']),
                ]),
                $reminderMinutes,
                $reminderChannel
            );

            $adminImg = $template->admin_img ?? [];
            if ($request->has('admin_img')) {
                $adminImg = CommonUse::handleImageUpdate(
                    $request,
                    'admin_img',
                    'AdminEmployeeTasksImages',
                    $template->admin_img ?? []
                );
            }

            $audio = $template->audio;
            if ($request->audio) {
                if (is_string($request->audio)) {
                    $audio = $template->audio;
                } elseif ($request->hasFile('audio')) {
                    $file = $request->file('audio');
                    $audioName = $file->getClientOriginalName();
                    $file->move(public_path('employeeTasksAudio'), $audioName);
                    $audio = $audioName;
                }
            }

            $template->update([
                'employee_id' => $data['employee_id'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'notes' => $data['notes'] ?? null,
                'points' => $data['points'],
                'priority' => $data['priority'] ?? 'medium',
                'is_forced_to_upload_img' => $request->boolean('is_forced_to_upload_img'),
                'requires_admin_review' => $request->boolean('requires_admin_review', true),
                'not_shown_for_employee' => $request->boolean('not_shown_for_employee'),
                'recurrence_type' => $data['task_recurrence'],
                'recurrence_config' => $recurrenceConfig,
                'admin_img' => $adminImg,
                'audio' => $audio,
            ]);

            $occurrence = null;
            if ($request->filled('occurrence_id')) {
                $occurrence = EmployeeTaskOccurrence::query()
                    ->where('id', $request->occurrence_id)
                    ->where('template_id', $template->id)
                    ->firstOrFail();

                $occurrence->update([
                    'employee_id' => $data['employee_id'],
                    'name' => $data['name'],
                    'description' => $data['description'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'points' => $data['points'],
                    'priority' => $data['priority'] ?? 'medium',
                    'is_forced_to_upload_img' => $request->boolean('is_forced_to_upload_img'),
                    'requires_admin_review' => $request->boolean('requires_admin_review', true),
                    'not_shown_for_employee' => $request->boolean('not_shown_for_employee'),
                    'start_time' => $data['start_time'],
                    'end_time' => $data['end_time'],
                    'admin_img' => $adminImg,
                    'audio' => $audio,
                ]);

                if ($occurrence->legacy_task_id) {
                    $legacy = EmployeeTask::find($occurrence->legacy_task_id);
                    if ($legacy) {
                        $assigneeService->syncForTask(
                            $legacy,
                            $assigneeIds !== [] ? $assigneeIds : [(int) $data['employee_id']]
                        );
                    }
                }
            }

            if ($request->has('sub_employee_tasks')) {
                $this->syncTemplateSubtasks($request, $template);
                if ($occurrence) {
                    $this->syncOccurrenceSubtasks($request, $occurrence);
                }
            }

            $template->load('employee.user');
            Logs::createLog(
                'تعديل مهمة موظف',
                'تم تعديل مهمة الموظف باسم '.$template->name
                    .' '.'التابعة للموظف'.' '.($template->employee->user->name ?? ''),
                'employee_tasks'
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.employee_task_updated_successfully'),
                'template_id' => $template->id,
                'occurrence_id' => $occurrence?->id,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 200);
        }
    }

    private function syncTemplateSubtasks(Request $request, EmployeeTaskTemplate $template): void
    {
        $existingIds = $template->subtasks()->pluck('id')->toArray();
        $keepIds = [];

        foreach ($request->sub_employee_tasks as $index => $subTaskData) {
            if (! empty($subTaskData['name'])) {
                $payload = [
                    'name' => $subTaskData['name'],
                    'description' => $subTaskData['description'] ?? null,
                    'sort_order' => $index,
                    'requires_image' => filter_var(
                        $subTaskData['is_forced_to_upload_img'] ?? false,
                        FILTER_VALIDATE_BOOLEAN
                    ),
                    'bonus_points' => (int) ($subTaskData['bonus_points'] ?? 0),
                ];

                if (! empty($subTaskData['id'])) {
                    $sub = EmployeeTaskTemplateSubtask::query()
                        ->where('id', $subTaskData['id'])
                        ->where('template_id', $template->id)
                        ->first();
                    if ($sub) {
                        $sub->update($payload);
                        $keepIds[] = $sub->id;

                        continue;
                    }
                }

                $created = EmployeeTaskTemplateSubtask::create(array_merge($payload, [
                    'template_id' => $template->id,
                ]));
                $keepIds[] = $created->id;
            }
        }

        $deleteIds = array_diff($existingIds, $keepIds);
        if ($deleteIds !== []) {
            EmployeeTaskTemplateSubtask::whereIn('id', $deleteIds)->delete();
        }
    }

    private function syncOccurrenceSubtasks(Request $request, EmployeeTaskOccurrence $occurrence): void
    {
        $existingIds = $occurrence->subtasks()->pluck('id')->toArray();
        $keepIds = [];

        foreach ($request->sub_employee_tasks as $index => $subTaskData) {
            if (empty($subTaskData['name'])) {
                continue;
            }

            $payload = [
                'name' => $subTaskData['name'],
                'description' => $subTaskData['description'] ?? null,
                'sort_order' => $index,
                'requires_image' => filter_var(
                    $subTaskData['is_forced_to_upload_img'] ?? false,
                    FILTER_VALIDATE_BOOLEAN
                ),
                'bonus_points' => (int) ($subTaskData['bonus_points'] ?? 0),
            ];

            $subImagesNames = null;
            if (! empty($subTaskData['id'])) {
                $sub = EmployeeTaskOccurrenceSubtask::query()
                    ->where('id', $subTaskData['id'])
                    ->where('occurrence_id', $occurrence->id)
                    ->first();
                if ($sub) {
                    $subImagesNames = $sub->admin_img ?? [];
                    if ($request->hasFile("sub_employee_tasks.$index.admin_subtask__img")) {
                        foreach ($request->file("sub_employee_tasks.$index.admin_subtask__img") as $file) {
                            $fullName = $file->getClientOriginalName();
                            $file->move(public_path('EmployeeSubTasks/AdminImages/'), $fullName);
                            $subImagesNames[] = $fullName;
                        }
                        $payload['admin_img'] = $subImagesNames;
                    }
                    $sub->update($payload);
                    $keepIds[] = $sub->id;

                    continue;
                }
            }

            if ($request->hasFile("sub_employee_tasks.$index.admin_subtask__img")) {
                $subImagesNames = [];
                foreach ($request->file("sub_employee_tasks.$index.admin_subtask__img") as $file) {
                    $fullName = $file->getClientOriginalName();
                    $file->move(public_path('EmployeeSubTasks/AdminImages/'), $fullName);
                    $subImagesNames[] = $fullName;
                }
                $payload['admin_img'] = $subImagesNames;
            }

            $created = EmployeeTaskOccurrenceSubtask::create(array_merge($payload, [
                'occurrence_id' => $occurrence->id,
                'status' => 'pending',
            ]));
            $keepIds[] = $created->id;
        }

        $deleteIds = array_diff($existingIds, $keepIds);
        if ($deleteIds !== []) {
            EmployeeTaskOccurrenceSubtask::whereIn('id', $deleteIds)->delete();
        }
    }

    public function completeOccurrenceSubtask(Request $request)
    {
        try {
            $request->validate(['sub_task_id' => 'required|exists:employee_task_occurrence_subtasks,id']);
            $sub = EmployeeTaskOccurrenceSubtask::findOrFail($request->sub_task_id);

            if ($sub->occurrence->employee_id != auth()->user()->employee->id) {
                return response()->json(['status' => 'error', 'message' => __('messages.unauthorized')], 200);
            }

            $this->workflow->completeOccurrenceSubtask($sub);

            $occurrence = $sub->occurrence->fresh();
            $pending = $occurrence->subtasks()->where('status', '!=', 'completed')->exists();

            if (! $pending) {
                if ($occurrence->is_forced_to_upload_img
                    && ! \App\Support\TaskMediaFiles::hasProof($occurrence->employee_img)) {
                    return response()->json([
                        'status' => 'success',
                        'message' => __('messages.subtask_completed_upload_proof'),
                        'all_subtasks_done' => true,
                    ], 200);
                }

                $this->workflow->submitOccurrenceForReview($occurrence);
            }

            return response()->json(['status' => 'success', 'message' => __('messages.task_completed')], 200);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 200);
        }
    }

    private function notifyCompleted($employee, $task): void
    {
        try {
            app(AdminNotificationService::class)->notifyTaskCompleted($employee, $task);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Admin notification: '.$e->getMessage());
        }
    }
}
