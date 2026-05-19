<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\EmployeeTask;
use App\Models\EmployeeTaskOccurrence;
use App\Models\EmployeeTaskOccurrenceSubtask;
use App\Models\EmployeeTaskTemplate;
use App\Models\EmployeeTaskTemplateSubtask;
use App\Models\EmployeeTaskTimeline;
use App\Services\EmployeeTasks\EmployeeTaskPerformanceService;
use App\Services\EmployeeTasks\EmployeeTaskRecurrenceService;
use App\Services\EmployeeTasks\EmployeeTaskTimelineService;
use App\Services\EmployeeTasks\EmployeeTaskWorkflowService;
use App\Services\AdminNotificationService;
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
                'employee_id' => 'required|exists:employee_details,id',
                'points' => 'required|integer|min:0',
                'priority' => 'nullable|in:low,medium,high',
                'start_time' => 'required|date',
                'end_time' => 'required|date|after_or_equal:start_time',
                'task_recurrence' => 'required|in:noRepeat,daily,weekly,monthly,yearly',
                'recurrence_config' => 'nullable|array',
                'not_shown_for_employee' => 'nullable|boolean',
                'is_forced_to_upload_img' => 'nullable|boolean',
                'sub_employee_tasks' => 'nullable|array',
                'sub_employee_tasks.*.name' => 'required|string|max:255',
                'sub_employee_tasks.*.description' => 'nullable|string',
                'sub_employee_tasks.*.is_forced_to_upload_img' => 'nullable|boolean',
                'sub_employee_tasks.*.bonus_points' => 'nullable|integer|min:0',
                'sub_employee_tasks.*.sort_order' => 'nullable|integer|min:0',
            ]);

            $recurrenceConfig = array_merge($data['recurrence_config'] ?? [], [
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'],
                'anchor_date' => $request->input('recurrence_config.anchor_date', $data['start_time']),
            ]);

            $template = EmployeeTaskTemplate::create([
                'employee_id' => $data['employee_id'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'notes' => $data['notes'] ?? null,
                'points' => $data['points'],
                'priority' => $data['priority'] ?? 'medium',
                'is_forced_to_upload_img' => $request->boolean('is_forced_to_upload_img'),
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

            $occurrences = $this->recurrence->ensureOccurrences($template);
            $summary = $this->recurrence->buildRecurrenceSummary($template);

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
                if ($occurrence->is_forced_to_upload_img && empty($occurrence->employee_img)) {
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
