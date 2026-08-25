<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Enums\EmployeeTaskStatus;
use App\Models\EmployeeTaskOccurrence;
use App\Services\EmployeeTasks\EmployeeLegacyDayInstanceService;
use App\Services\EmployeeTasks\EmployeeTaskDetailsService;
use App\Services\EmployeeTasks\EmployeeTaskListService;
use App\Services\EmployeeTasks\EmployeeTaskAssigneeService;
use App\Services\EmployeeTasks\EmployeeTaskCancellationService;
use App\Services\EmployeeTasks\EmployeeTaskNotificationService;
use App\Services\EmployeeTasks\EmployeeTaskTimelineService;
use App\Services\EmployeeTasks\EmployeeTaskWorkflowService;
use App\Services\EmployeeActivityLogger;
use App\Support\SubtaskAdminMediaStorage;
use App\Support\TaskProofMediaType;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use App\Models\EmployeeSubTask;
use App\Models\EmployeeTask;
use App\Models\EmployeeTaskTemplate;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class EmployeeTasks extends Controller
{
    public function __construct(
        private readonly EmployeeTaskListService $listService,
        private readonly EmployeeTaskWorkflowService $workflow,
        private readonly EmployeeTaskTimelineService $timeline,
        private readonly EmployeeTaskCancellationService $cancellationService
    ) {}

    private function employeeProfilePhoto($employee): string
    {
        if (! $employee || ! $employee->employee_img) {
            return 'no images';
        }

        return 'public/EmployeeImages/'.$employee->employee_img[0];
    }

    private function sameAssigneeSet(array $oldIds, array $newIds): bool
    {
        $old = collect($oldIds)->map(fn ($id) => (int) $id)->filter()->unique()->sort()->values()->all();
        $new = collect($newIds)->map(fn ($id) => (int) $id)->filter()->unique()->sort()->values()->all();

        return $old === $new;
    }

    private function shouldResetCompletionForReassignment(EmployeeTask $task, array $oldAssigneeIds, array $newAssigneeIds): bool
    {
        if ($newAssigneeIds === [] || $this->sameAssigneeSet($oldAssigneeIds, $newAssigneeIds)) {
            return false;
        }

        return in_array(EmployeeTaskStatus::normalize($task->status), [
            EmployeeTaskStatus::WaitingReview,
            EmployeeTaskStatus::Completed,
        ], true);
    }

    private function proofMediaTypeFromInput(array|Request $input, string $key, bool $required): string
    {
        $value = $input instanceof Request ? $input->input($key) : ($input[$key] ?? null);

        return TaskProofMediaType::fromRequestValue($value, $required);
    }

    private function storeSubtaskAdminUpload(\Illuminate\Http\UploadedFile $file): string
    {
        return SubtaskAdminMediaStorage::store($file);
    }

    private function resetEmployeeTaskSubtasksCompletion(EmployeeTask $task): void
    {
        $payload = ['status' => EmployeeTaskStatus::Pending->value];

        if (Schema::hasColumn('sub_employee_tasks', 'completed_by_employee_id')) {
            $payload['completed_by_employee_id'] = null;
        }

        if (Schema::hasColumn('sub_employee_tasks', 'employee_img')) {
            $payload['employee_img'] = null;
        }

        $task->subTasks()->update($payload);
    }

    private function requireAdminUser(Request $request)
    {
        $user = $request->user();

        if (! $user || $user->type !== 'admin') {
            abort(response()->json([
                'status' => 'error',
                'message' => __('messages.unauthorized'),
            ], 200));
        }

        return $user;
    }

    private function employeeTaskPurgePreview(): array
    {
        $legacy = EmployeeTask::query();
        $occurrences = EmployeeTaskOccurrence::query();

        return [
            'employee_tasks_count' => (clone $legacy)->count(),
            'sub_tasks_count' => EmployeeSubTask::query()->count(),
            'occurrences_count' => Schema::hasTable('employee_task_occurrences')
                ? (clone $occurrences)->count()
                : 0,
            'occurrence_subtasks_count' => Schema::hasTable('employee_task_occurrence_subtasks')
                ? DB::table('employee_task_occurrence_subtasks')->count()
                : 0,
            'templates_count' => Schema::hasTable('employee_task_templates')
                ? DB::table('employee_task_templates')->count()
                : 0,
            'future_tasks_count' => (clone $legacy)->where('end_time', '>=', now())->count(),
            'completed_tasks_count' => (clone $legacy)->where('status', EmployeeTaskStatus::Completed->value)->count(),
            'canceled_tasks_count' => (clone $legacy)->where('is_canceled', 1)->count(),
            'oldest_start_time' => (clone $legacy)->min('start_time'),
            'latest_end_time' => (clone $legacy)->max('end_time'),
        ];
    }

    private function assigneeNamesForTask(EmployeeTask $task): string
    {
        $ids = app(EmployeeTaskAssigneeService::class)->idsForTask($task);

        return $this->assigneeNamesFromIds($ids);
    }

    private function assigneeNamesFromIds(array $ids): string
    {
        return \App\Models\EmployeeDetail::query()
            ->with('user')
            ->whereIn('id', $ids)
            ->get()
            ->map(fn ($employee) => $employee->user?->name ?? ('#'.$employee->id))
            ->filter()
            ->implode("\n");
    }

    private function assigneeIdsForOccurrence(EmployeeTaskOccurrence $occurrence): array
    {
        if ($occurrence->legacy_task_id) {
            $legacy = EmployeeTask::find($occurrence->legacy_task_id);
            if ($legacy) {
                return app(EmployeeTaskAssigneeService::class)->idsForTask($legacy);
            }
        }

        return [(int) $occurrence->employee_id];
    }

    private function assigneeNamesForOccurrence(EmployeeTaskOccurrence $occurrence): string
    {
        return $this->assigneeNamesFromIds($this->assigneeIdsForOccurrence($occurrence));
    }

    private function employeeTaskExportKey(string $name, array $assigneeIds): string
    {
        $ids = collect($assigneeIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->implode(',');

        return mb_strtolower(trim($name)).'|'.$ids;
    }

    private function formatSubtasksForExport($subtasks): string
    {
        return collect($subtasks)
            ->values()
            ->map(function ($subtask, int $index) {
                $parts = array_filter([
                    trim((string) ($subtask?->name ?? '')),
                    trim((string) ($subtask?->description ?? '')),
                    trim((string) ($subtask?->status ?? '')),
                ]);

                return ($index + 1).'. '.implode(' - ', $parts);
            })
            ->filter(fn ($line) => trim($line) !== '')
            ->unique()
            ->implode("\n");
    }

    public function exportFutureEmployeeTasks()
    {
        $futureCutoff = now()->startOfDay();
        $legacyTaskIdsWithFutureOccurrences = EmployeeTaskOccurrence::query()
            ->where('is_canceled', 0)
            ->where('end_time', '>=', $futureCutoff)
            ->whereNotNull('legacy_task_id')
            ->pluck('legacy_task_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $parentTaskIdsWithFutureChildren = EmployeeTask::query()
            ->where('is_canceled', 0)
            ->where('end_time', '>=', $futureCutoff)
            ->whereNotNull('parent_id')
            ->pluck('parent_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $legacyTaskIdsToSkip = array_values(array_unique(array_merge(
            $legacyTaskIdsWithFutureOccurrences,
            $parentTaskIdsWithFutureChildren
        )));

        $tasks = EmployeeTask::query()
            ->with(['employee.user', 'subTasks'])
            ->where('is_canceled', 0)
            ->where('end_time', '>=', $futureCutoff)
            ->when(
                $legacyTaskIdsToSkip !== [],
                fn ($query) => $query->whereNotIn('id', $legacyTaskIdsToSkip)
            )
            ->orderBy('start_time')
            ->get();

        $occurrences = EmployeeTaskOccurrence::query()
            ->with(['employee.user', 'subtasks'])
            ->where('is_canceled', 0)
            ->where('end_time', '>=', $futureCutoff)
            ->orderBy('start_time')
            ->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Future Tasks');
        $sheet->setRightToLeft(true);

        $headers = [
            'اسم المهمة',
            'تفاصيل المهمة',
            'ملاحظات المهمة',
            'الأشخاص المسؤولين',
            'أقرب تاريخ بداية',
            'آخر تاريخ نهاية',
            'حالة المهمة',
            'عدد النسخ المستقبلية',
            'المهام الفرعية',
        ];
        $sheet->fromArray($headers, null, 'A1');

        $groupedRows = [];

        foreach ($tasks as $task) {
            $assigneeIds = app(EmployeeTaskAssigneeService::class)->idsForTask($task);
            $key = $this->employeeTaskExportKey((string) $task->name, $assigneeIds);
            $start = $task->start_time ? Carbon::parse($task->start_time) : null;
            $end = $task->end_time ? Carbon::parse($task->end_time) : null;

            $groupedRows[$key] ??= [
                'name' => $task->name,
                'description' => $task->description,
                'notes' => $task->notes,
                'assignees' => $this->assigneeNamesFromIds($assigneeIds),
                'start' => $start,
                'end' => $end,
                'status' => $task->status,
                'count' => 0,
                'subtasks' => '',
            ];
            $groupedRows[$key]['count']++;
            if ($start && (! $groupedRows[$key]['start'] || $start->lt($groupedRows[$key]['start']))) {
                $groupedRows[$key]['start'] = $start;
            }
            if ($end && (! $groupedRows[$key]['end'] || $end->gt($groupedRows[$key]['end']))) {
                $groupedRows[$key]['end'] = $end;
            }
            $subtasks = $this->formatSubtasksForExport($task->subTasks);
            if ($subtasks !== '') {
                $groupedRows[$key]['subtasks'] = $subtasks;
            }
        }

        foreach ($occurrences as $occurrence) {
            $assigneeIds = $this->assigneeIdsForOccurrence($occurrence);
            $key = $this->employeeTaskExportKey((string) $occurrence->name, $assigneeIds);

            $groupedRows[$key] ??= [
                'name' => $occurrence->name,
                'description' => $occurrence->description,
                'notes' => $occurrence->notes,
                'assignees' => $this->assigneeNamesFromIds($assigneeIds),
                'start' => $occurrence->start_time,
                'end' => $occurrence->end_time,
                'status' => $occurrence->status,
                'count' => 0,
                'subtasks' => '',
            ];
            $groupedRows[$key]['count']++;
            if ($occurrence->start_time && (! $groupedRows[$key]['start'] || $occurrence->start_time->lt($groupedRows[$key]['start']))) {
                $groupedRows[$key]['start'] = $occurrence->start_time;
            }
            if ($occurrence->end_time && (! $groupedRows[$key]['end'] || $occurrence->end_time->gt($groupedRows[$key]['end']))) {
                $groupedRows[$key]['end'] = $occurrence->end_time;
            }
            $subtasks = $this->formatSubtasksForExport($occurrence->subtasks);
            if ($subtasks !== '') {
                $groupedRows[$key]['subtasks'] = $subtasks;
            }
        }

        $row = 2;
        foreach (collect($groupedRows)->sortBy('start') as $data) {
            $subtaskLineCount = max(1, substr_count((string) $data['subtasks'], "\n") + 1);
            $assigneeLineCount = max(1, substr_count((string) $data['assignees'], "\n") + 1);
            $rowLineCount = max($subtaskLineCount, $assigneeLineCount);
            $sheet->fromArray([
                $data['name'],
                $data['description'],
                $data['notes'],
                $data['assignees'],
                $data['start'] ? $data['start']->toDateTimeString() : null,
                $data['end'] ? $data['end']->toDateTimeString() : null,
                $data['status'],
                $data['count'],
                $data['subtasks'],
            ], null, 'A'.$row);
            $sheet->getRowDimension($row)->setRowHeight(max(36, min(360, $rowLineCount * 22)));
            $row++;
        }

        $lastRow = max(1, $row - 1);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:I'.$lastRow);
        $sheet->getRowDimension(1)->setRowHeight(28);

        $sheet->getStyle('A1:I1')->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size' => 12,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4F46E5'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        $sheet->getStyle('A1:I'.$lastRow)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'D9E2EC'],
                ],
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_TOP,
                'wrapText' => true,
            ],
        ]);

        if ($lastRow >= 2) {
            $sheet->getStyle('A2:A'.$lastRow)->getFont()->setBold(true);
            $sheet->getStyle('E2:H'.$lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        $widths = [
            'A' => 28,
            'B' => 38,
            'C' => 32,
            'D' => 28,
            'E' => 20,
            'F' => 20,
            'G' => 16,
            'H' => 14,
            'I' => 55,
        ];

        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $fileName = 'future_employee_tasks_with_subtasks_'.now()->format('Y-m-d').'.xlsx';
        $tempPath = tempnam(sys_get_temp_dir(), 'employee_tasks_export_');
        (new Xlsx($spreadsheet))->save($tempPath);

        return response()->download($tempPath, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    public function clearEmployeeTasksPreview(Request $request)
    {
        $this->requireAdminUser($request);

        return response()->json([
            'status' => 'success',
            'data' => $this->employeeTaskPurgePreview(),
        ], 200);
    }

    public function clearEmployeeTasks(Request $request)
    {
        $user = $this->requireAdminUser($request);
        $request->validate([
            'password' => 'required|string',
            'confirmation' => 'nullable|string',
        ]);

        if (! Hash::check($request->password, $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'كلمة المرور غير صحيحة',
            ], 200);
        }

        $preview = $this->employeeTaskPurgePreview();

        DB::transaction(function () {
            if (Schema::hasTable('employee_task_timeline')) {
                DB::table('employee_task_timeline')->delete();
            }
            if (Schema::hasTable('employee_task_assignees')) {
                DB::table('employee_task_assignees')->delete();
            }
            if (Schema::hasTable('employee_task_occurrence_subtasks')) {
                DB::table('employee_task_occurrence_subtasks')->delete();
            }
            if (Schema::hasTable('employee_task_occurrences')) {
                DB::table('employee_task_occurrences')->delete();
            }
            EmployeeSubTask::query()->delete();
            EmployeeTask::query()->delete();
            if (Schema::hasTable('employee_task_template_subtasks')) {
                DB::table('employee_task_template_subtasks')->delete();
            }
            if (Schema::hasTable('employee_task_templates')) {
                DB::table('employee_task_templates')->delete();
            }
        });

        Logs::createLog(
            'تفريغ مهام الموظفين',
            'قام الأدمن '.$user->name.' بتفريغ كل مهام الموظفين. العدد: '.json_encode($preview, JSON_UNESCAPED_UNICODE),
            'employee_tasks'
        );

        return response()->json([
            'status' => 'success',
            'message' => 'تم تفريغ مهام الموظفين بنجاح',
            'data' => $preview,
        ], 200);
    }

    public function sendEmployeeTaskReminder(Request $request)
    {
        $this->requireAdminUser($request);
        $request->validate([
            'employee_task_id' => 'required_without:occurrence_id|nullable|exists:employee_tasks,id',
            'occurrence_id' => 'required_without:employee_task_id|nullable|exists:employee_task_occurrences,id',
            'note' => 'nullable|string|max:1000',
        ]);

        $note = trim((string) $request->input('note', ''));
        $notificationService = app(\App\Services\EmployeeNotificationService::class);
        $assigneeService = app(EmployeeTaskAssigneeService::class);
        $sent = 0;
        $recipients = [];

        if ($request->filled('occurrence_id')) {
            $occurrence = EmployeeTaskOccurrence::with('employee.user')->findOrFail($request->occurrence_id);
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

            foreach (array_unique($ids) as $employeeId) {
                $employee = \App\Models\EmployeeDetail::with('user')->find($employeeId);
                if (! $employee) {
                    continue;
                }
                $body = 'يرجى تنفيذ المهمة: '.$occurrence->name;
                if ($occurrence->description) {
                    $body .= "\n".$occurrence->description;
                }
                if ($note !== '') {
                    $body .= "\nملاحظة الأدمن: ".$note;
                }
                $notificationService->create($employee, 'employee_task_manual_reminder', 'تذكير بتنفيذ مهمة', $body, [
                    'task_id' => (string) ($occurrence->legacy_task_id ?? ''),
                    'occurrence_id' => (string) $occurrence->id,
                    'task_name' => $occurrence->name,
                    'admin_note' => $note,
                ], 'employee_task_occurrence', (int) $occurrence->id, true);
                $sent++;
                $recipients[] = $employee->user?->name ?? ('#'.$employee->id);
            }
        } else {
            $task = EmployeeTask::with('employee.user')->findOrFail($request->employee_task_id);
            foreach ($assigneeService->idsForTask($task) as $employeeId) {
                $employee = \App\Models\EmployeeDetail::with('user')->find($employeeId);
                if (! $employee) {
                    continue;
                }
                $body = 'يرجى تنفيذ المهمة: '.$task->name;
                if ($task->description) {
                    $body .= "\n".$task->description;
                }
                if ($note !== '') {
                    $body .= "\nملاحظة الأدمن: ".$note;
                }
                $notificationService->create($employee, 'employee_task_manual_reminder', 'تذكير بتنفيذ مهمة', $body, [
                    'task_id' => (string) $task->id,
                    'task_name' => $task->name,
                    'admin_note' => $note,
                ], 'employee_task', (int) $task->id, true);
                $sent++;
                $recipients[] = $employee->user?->name ?? ('#'.$employee->id);
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'تم إرسال التذكير إلى '.$sent.' موظف',
            'sent_count' => $sent,
            'recipients' => $recipients,
        ], 200);
    }

    private function resetEmployeeTaskCompletion(EmployeeTask $task): void
    {
        $task->update([
            'status' => EmployeeTaskStatus::Pending->value,
            'completed_by_employee_id' => null,
            'employee_img' => null,
            'started_at' => null,
            'submitted_at' => null,
            'reviewed_at' => null,
            'rejection_notes' => null,
        ]);

        $this->resetEmployeeTaskSubtasksCompletion($task->fresh());
    }

    private function syncTaskSeriesAfterReassignment(EmployeeTask $parentTask, array $assigneeIds): void
    {
        $ids = collect($assigneeIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return;
        }

        $primaryEmployeeId = $ids[0];
        $assigneeService = app(EmployeeTaskAssigneeService::class);

        EmployeeTask::query()
            ->where('id', $parentTask->id)
            ->orWhere('parent_id', $parentTask->id)
            ->get()
            ->each(function (EmployeeTask $task) use ($ids, $primaryEmployeeId, $assigneeService) {
                if ((int) $task->employee_id !== $primaryEmployeeId) {
                    $task->update(['employee_id' => $primaryEmployeeId]);
                }

                $assigneeService->syncForTask($task->fresh(), $ids);

                if (in_array(EmployeeTaskStatus::normalize($task->status), [
                    EmployeeTaskStatus::WaitingReview,
                    EmployeeTaskStatus::Completed,
                ], true)) {
                    $this->resetEmployeeTaskCompletion($task->fresh());
                }
            });
    }

    private function taskSeriesNeedsAssigneeSync(EmployeeTask $parentTask, array $oldAssigneeIds, array $targetAssigneeIds): bool
    {
        if (! $this->sameAssigneeSet($oldAssigneeIds, $targetAssigneeIds)) {
            return true;
        }

        $ids = collect($targetAssigneeIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return false;
        }

        $primaryEmployeeId = $ids[0];
        $assigneeService = app(EmployeeTaskAssigneeService::class);

        return EmployeeTask::query()
            ->where('id', $parentTask->id)
            ->orWhere('parent_id', $parentTask->id)
            ->get()
            ->contains(function (EmployeeTask $task) use ($ids, $primaryEmployeeId, $assigneeService) {
                return (int) $task->employee_id !== $primaryEmployeeId
                    || ! $this->sameAssigneeSet($assigneeService->idsForTask($task), $ids);
            });
    }

//     private function getTasks($status){
//    try {
//         $tasks = EmployeeTask::with('employee')
//             ->where('status', $status)
//             ->where('is_canceled', 0)
//             ->get();

//         // $today = now();
//         // $todayDayName = strtolower($today->format('l')); // e.g. "monday"
//         // $todayDayOfMonth = (int)$today->format('d'); // e.g. 15

//         // // Filter based on recurrence
//         // $filtered = $tasks->filter(function ($task) use ($todayDayName, $todayDayOfMonth) {
//         //     $recurrence = $task->task_recurrence;
//         //     $times = $task->task_recurrence_time ?? [];

//         //     if ($recurrence === 'noRepeat') {
//         //         // Non-recurring: show only if created today or as per your logic
//         //         return true;
//         //     }

//         //     if ($recurrence === 'daily') {
//         //         return true; // Every day
//         //     }

//         //     if ($recurrence === 'weekly') {
//         //         // Match today's day name
//         //         return in_array($todayDayName, $times);
//         //     }

//         //     if ($recurrence === 'monthly') {
//         //         // Match today's date (e.g., 15)
//         //         return in_array($todayDayOfMonth, array_map('intval', $times));
//         //     }

//         //     return false;
//         // });

//         $formatted = $tasks->map(function ($task) {
//             return [
//                 'task_id' => $task->id,
//                 'task_name' => $task->name,
//                 'employee_id' => $task->employee_id,
//                 'employee_name' => $task->employee->user->name ?? 'unknown',
//                 'start_time' => $task->start_time,
//                 'end_time' => $task->end_time,
//                 'is_canceled' => $task->is_canceled,
//                 'employee_img' => $task->employee_img
//                     ? 'public/EmployeeTasksImages/' . $task->employee_img[0]
//                     : 'no employee image',
//                 'admin_img' => (is_array($task->admin_img) && count($task->admin_img) > 0)
//                     ? 'public/AdminEmployeeTasksImages/' . $task->admin_img[0]
//                     : 'no admin image',
//                 'audio' => $task->audio
//                     ? 'public/employeeTasksAudio/' . $task->audio
//                     : 'no audio',
//                 'parent_id' => $task->parent_id,
//             ];
//         });//->values();

//             return $formatted;

//     } catch (QueryException $e) {
//         return response([
//             'status' => 'error',
//             'message' => __('messages.retrieve_data_error'),
//         ], 200);
//     } catch (\Exception $e) {
//         return response()->json([
//             'status' => 'error',
//             'message' => __('messages.something_wrong'),
//         ], 200);
//     }
//   }


private function getTasks($status)
{
    try {
        $tasks = EmployeeTask::with('employee')
            ->where('status', $status)
            ->where('is_canceled', 0)
            ->get();

       $filtered = $tasks->filter(function ($task) {
            $recurrence = $task->task_recurrence;
            $times = is_array($task->task_recurrence_time) ? $task->task_recurrence_time : [];
            $dayName = strtolower(\Carbon\Carbon::parse($task->start_time)->format('l'));
            $dayOfMonth = (int) \Carbon\Carbon::parse($task->start_time)->format('d');

            switch ($recurrence) {
                case 'noRepeat':
                case 'oneTimePersistent':
                    return true; // no restriction

                case 'daily':
                    return true; // appears every day

                case 'weekly':
                    // only if today's weekday is included in recurrence time
                    return in_array($dayName, $times);

                case 'monthly':
                    // only if today's date matches the recurrence time number
                    return in_array((string)$dayOfMonth, $times);

                default:
                    return false;
            }
        });

        $formatted = $filtered->map(function ($task) {
            return [
                'task_id' => $task->id,
                'display_number' => $task->display_number,
                'task_name' => $task->name,
                'employee_id' => $task->employee_id,
                'employee_name' => $task->employee->user->name ?? 'unknown',
                'employee_photo' => $this->employeeProfilePhoto($task->employee),
                'start_time' => $task->start_time,
                'end_time' => $task->end_time,
                'is_canceled' => $task->is_canceled,
                'employee_img' => $task->employee_img
                    ? 'public/EmployeeTasksImages/' . $task->employee_img[0]
                    : 'no employee image',
                'admin_img' => (is_array($task->admin_img) && count($task->admin_img) > 0)
                    ? 'public/AdminEmployeeTasksImages/' . $task->admin_img[0]
                    : 'no admin image',
                'audio' => $task->audio
                    ? 'public/employeeTasksAudio/' . $task->audio
                    : 'no audio',
                'parent_id' => $task->parent_id,
            ];
        })->values();

        return $formatted;
    } catch (QueryException $e) {
        return response([
            'status' => 'error',
            'message' => __('messages.retrieve_data_error'),
        ], 200);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.something_wrong'),
        ], 200);
    }
}

    public function completedTasks(){
        $formatted = $this->listService->getCompletedItems(
            fn ($employee) => $this->employeeProfilePhoto($employee)
        );

        return response([
            'status' => 'success',
            'completed employee tasks' => $formatted,
        ], 200);
    }

    public function ongoingTasks()
    {
        $formatted = $this->listService->getOngoingItems(
            fn ($employee) => $this->employeeProfilePhoto($employee)
        );

        return response()->json([
            'status' => 'success',
            'ongoing employee tasks' => $formatted,
        ], 200);
    } 


    public function canceledTasks()
    {
        try {
            $formatted = $this->listService->getCanceledItems(
                fn ($employee) => $this->employeeProfilePhoto($employee)
            );

            return response([
                'status' => 'success',
                'canceled employee tasks' => $formatted,
            ], 200);
        } catch (QueryException $e) {
            return response([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    
    public function cancelEmployeeTask(Request $request){
        try{
        $request->validate([
            'employee_task_id' => 'nullable|exists:employee_tasks,id',
            'occurrence_id' => 'nullable|exists:employee_task_occurrences,id',
        ]);

        if (! $request->filled('employee_task_id') && ! $request->filled('occurrence_id')) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);
        }

        if ($request->filled('occurrence_id')) {
            $occurrence = $this->cancellationService->cancelOccurrence((int) $request->occurrence_id);

            Logs::createLog(
                'الغاء مهمة موظف',
                ' الغاء مهمة موظف باسم '.$occurrence->name
                    .' '.'التابعة للموظف '.($occurrence->employee->user->name ?? ''),
                'employee_tasks'
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.employee_task_canceled'),
            ], 200);
        }

        $ongoingTask = $this->cancellationService->cancelLegacyTask((int) $request->employee_task_id);
        Logs::createLog('الغاء مهمة موظف',' الغاء مهمة موظف باسم'.' '.$ongoingTask->name
        .' '.'التابعة للموظف'.' '.($ongoingTask->employee?->user?->name ?? '')
        
        ,'employee_tasks');
            return response()->json([
                'status' => 'success',
                'message' => __('messages.employee_task_canceled')],200);
        
    }

    catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);}

    catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.employee_task_not_found')], 200);
        }    
    catch (\Exception $e) {
             return response()->json([
                'status' => 'error',
                'message' => __('messages.failed_to_cancel_task'),
            ], 200);        }
}

//     public function restoreEmployeeTask(Request $request){
//         try{
//         $request->validate(['employee_task_id'=>'required|exists:employee_tasks,id']);

//         $ongoingTask = EmployeeTask::findOrFail($request->employee_task_id);
       
//         $ongoingTask->update(['is_canceled'=>0]);
//         Logs::createLog('استعادة مهمة موظف','تم استعادة مهمة موظف باسم'.' '.$ongoingTask->name,'employee_tasks');

//             return response()->json([
//                 'status' => 'success',
//                 'message' => __('messages.employee_task_restored')],200);
        
//     }

//     catch (ValidationException $e) {
//             return response()->json([
//                 'status' => 'error',
//                 'message' => __('messages.validation_failed'),
//             ], 200);}

//     catch (ModelNotFoundException $e) {
//             return response()->json([
//                 'status' => 'error',
//                 'message' => __('messages.employee_task_not_found')], 200);
//         }    
//     catch (\Exception $e) {
//              return response()->json([
//                 'status' => 'error',
//                 'message' => __('messages.failed_to_restore_task'),
//             ], 200);        }
// }


    public function cancelEmployeeTaskWithRepetition(Request $request){
        try{
        $request->validate([
            'employee_task_id' => 'nullable|exists:employee_tasks,id',
            'occurrence_id' => 'nullable|exists:employee_task_occurrences,id',
        ]);

        if ($request->filled('occurrence_id')) {
            $occurrence = $this->cancellationService->cancelOccurrenceSeries((int) $request->occurrence_id);

            Logs::createLog(
                'الغاء مهمة مع التكرار',
                ' الغاء مهمة موظف مع التكرار باسم '.$occurrence->name
                    .' التابعة للموظف '.($occurrence->employee?->user?->name ?? ''),
                'employee_tasks'
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.employee_task_canceled'),
            ], 200);
        }

        $request->validate(['employee_task_id' => 'required|exists:employee_tasks,id']);

        $ongoingTask = $this->cancellationService->cancelLegacySeries((int) $request->employee_task_id);


        Logs::createLog('الغاء مهمة مع التكرار',' الغاء مهمة موظف مع التكرار باسم'.' '.$ongoingTask->name
        
        .' '.'التابعة للموظف'.' '.($ongoingTask->employee?->user?->name ?? '')
        
        ,
        'employee_tasks');
            return response()->json([
                'status' => 'success',
                'message' => __('messages.employee_task_canceled')],200);
        
    }

    catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);}

    catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.employee_task_not_found')], 200);
        }    
    catch (\Exception $e) {
             return response()->json([
                'status' => 'error',
                'message' => __('messages.failed_to_cancel_task'),
            ], 200);        }
}



// public static function createHelper(Model $task, string $recurrence): void
// {
//     $recurrenceCounts = [
//         'daily' => 29,   // 30 total (main + 29 repeats)
//         'weekly' => 3,   // 4 total (main + 3 repeats)
//         'monthly' => 0,  // 3 total (main + 2 repeats)
//         'noRepeat' => 0,
//     ];

//     $count = $recurrenceCounts[$recurrence] ?? 0;
//     $start = \Carbon\Carbon::parse($task->start_time);
//     $end = \Carbon\Carbon::parse($task->end_time);

//     for ($i = 1; $i <= $count; $i++) {
//         $newStart = $start->copy();
//         $newEnd = $end->copy();

//         //  Adjust time based on recurrence type
//         switch ($recurrence) {
//             case 'daily':
//                 $newStart->addDays($i);
//                 $newEnd->addDays($i);
//                 break;
//             case 'weekly':
//                 $newStart->addWeeks($i);
//                 $newEnd->addWeeks($i);
//                 break;
//             case 'monthly':
//                 $newStart->addMonths($i);
//                 $newEnd->addMonths($i);
//                 break;
//         }

//         //  Duplicate record
//         $data = $task->replicate()->toArray();
//         $data['parent_id'] = $task->id;
//         $data['start_time'] = $newStart->format('Y-m-d H:i:s');
//         $data['end_time'] = $newEnd->format('Y-m-d H:i:s');

//         $task::create($data);
//     }
// }

// public static function createHelper(Model $task, string $recurrence): void
// {
//     $start = Carbon::parse($task->start_time);
//     $end = Carbon::parse($task->end_time);
//     $recurrenceDays = is_array($task->task_recurrence_time) ? $task->task_recurrence_time : [];

//     if ($end->lessThanOrEqualTo($start)) {
//         return; // invalid range
//     }

//     // We'll move a cursor through time
//     $current = $start->copy();

//     while ($current->lessThanOrEqualTo($end)) {
//         switch ($recurrence) {
//             case 'daily':
//                 // Add 1 day each loop
//                 $current->addDay();

//                 if ($current->greaterThan($end)) break 2;

//                 self::duplicateTask($task, $current, $end);
//                 break;

//             case 'weekly':
//                 // For weekly, we go week by week and create for each chosen day
//                 $weekStart = $current->copy()->startOfWeek(); // beginning of current week

//                 foreach ($recurrenceDays as $day) {
//                     $dayCarbon = Carbon::parse($weekStart)->next(strtolower($day));

//                     // Only create if before end date
//                     if ($dayCarbon->greaterThan($end)) continue;

//                     // Skip if before start_time (first week edge case)
//                     if ($dayCarbon->lessThanOrEqualTo($start)) continue;

//                     self::duplicateTask($task, $dayCarbon, $end);
//                 }

//                 // Move to next week
//                 $current->addWeek();
//                 break;

//             case 'monthly':
//                  $current->addMonth();

//                 if ($current->greaterThan($end)) break 2;

//                 self::duplicateTask($task, $current, $end);
//                 break;

//             default:
//                 return; // noRepeat or invalid
//         }
//     }
// }

    /**
     * @param  array<int>  $assigneeIds
     */
    public static function activeRecurringTemplateExists(array $assigneeIds, string $name): bool
    {
        if (! Schema::hasTable('employee_task_templates')) {
            return false;
        }

        $query = EmployeeTaskTemplate::query()
            ->where('is_active', true)
            ->where('name', $name);

        if ($assigneeIds !== []) {
            $query->whereIn('employee_id', $assigneeIds);
        }

        return $query->exists();
    }

public const LEGACY_PREFILL_HORIZON_DAYS = 14;

public static function createHelper(Model $task, string $recurrence): void
{
    $start = Carbon::parse($task->start_time);
    $end = Carbon::parse($task->end_time);
    $recurrenceDays = is_array($task->task_recurrence_time) ? $task->task_recurrence_time : [];

    if ($end->lessThan($start)) {
        return; // invalid range
    }

    $horizonEnd = $start->copy()->addDays(self::LEGACY_PREFILL_HORIZON_DAYS);
    if ($horizonEnd->greaterThan($end)) {
        $horizonEnd = $end->copy();
    }

    $current = $start->copy();

    while ($current->lessThanOrEqualTo($horizonEnd)) {
        switch ($recurrence) {
            case 'daily':
                $current->addDay();
                if ($current->greaterThan($end)) break 2;
                self::duplicateTask($task, $current, $end);
                break;

            case 'weekly':
                // We'll check all recurrence days within the current week
                $weekStart = $current->copy()->startOfWeek();

                foreach ($recurrenceDays as $day) {
                    $dayCarbon = Carbon::parse($weekStart)->next(strtolower($day));

                    if ($dayCarbon->lessThan($weekStart)) {
                        $dayCarbon = $weekStart->copy();
                    }

                    if ($dayCarbon->betweenIncluded($start, $end)) {
                        self::duplicateTask($task, $dayCarbon, $end);
                    }
                }

                // Move to next week
                $current->addWeek();
                break;

            case 'monthly':
                $current->addMonth();
                if ($current->greaterThan($end)) break 2;
                self::duplicateTask($task, $current, $end);
                break;

            default:
                return;
        }
    }

    // ✅ Handle special case: ensure an instance on the END DATE if it matches recurrence
    // if ($recurrence === 'weekly' && in_array(strtolower($end->format('l')), $recurrenceDays)) {
    //     // check if not already created at end date
    //     $exists = $task->whereDate('start_time', $end->format('Y-m-d'))
    //                    ->where('parent_id', $task->id)
    //                    ->exists();
    //     if (!$exists) {
    //         self::duplicateTask($task, $end, $end);
    //     }
    // }
}


/**
 * Helper: Duplicate a task to a new date
 */
protected static function duplicateTask(Model $task, Carbon $newStart, Carbon $mainEnd): void
{
    $anchorDay = Carbon::parse($task->start_time)->startOfDay();
    if ($newStart->copy()->startOfDay()->equalTo($anchorDay)) {
        return;
    }

    $data = $task->replicate()->toArray();
    $data['parent_id'] = $task->id;
    $data['start_time'] = $newStart->format('Y-m-d H:i:s');
    $data['end_time'] = $mainEnd->format('Y-m-d H:i:s'); // always same as main
    unset($data['display_number'], $data['occurrence_id'], $data['template_id']);
    $newTask= $task::create($data);

    if ($task instanceof EmployeeTask && $newTask instanceof EmployeeTask) {
        app(EmployeeTaskAssigneeService::class)->copyFromParent($task, $newTask);
    }

    $subtasks = $task->subTasks()->get();

    foreach ($subtasks as $subtask) {
            $subData = $subtask->replicate()->toArray();
            $subData['employee_task_id'] = $newTask->id; // link to new recurrent task
            unset($subData['occurrence_id']);
            $subData['status'] = 'pending';
            $subData['employee_img'] = null;
            if (array_key_exists('completed_by_employee_id', $subData)) {
                $subData['completed_by_employee_id'] = null;
            }
            EmployeeSubTask::create($subData);
        }
}



    public static function mediaHelper(Request $request ,String $imgPath){
        $adminImages=[];
        if ($request->hasFile('admin_img')) {
            foreach($request->file('admin_img') as $image){
                    $imageName = $image->getClientOriginalName();
                    $destinationPath = public_path($imgPath); 
                    $image->move(public_path($imgPath), $imageName);    
                    $adminImages[] = $imageName;
            }

   }

        return $adminImages;
    }

    public function createEmployeeTask(Request $request){
        try{
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'employee_id' => ['required_without:employee_ids','exists:employee_details,id'],
            'employee_ids' => ['nullable', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'exists:employee_details,id'],
            'points' => ['required', 'integer', 'min:0'],
            'start_time' => ['required', 'date', 'before_or_equal:end_time'],
            'end_time' => ['required', 'date', 'after_or_equal:start_time'],
            'task_recurrence' => ['required', 'string','in:noRepeat,oneTimePersistent,daily,weekly,monthly'],
            'proof_media_type' => ['nullable', 'string', 'in:none,image,video,both'],
          
            'task_recurrence_time' => [
                'nullable',
                'array',
               // 'required_unless:task_recurrence,noRepeat',
            ],
            'task_recurrence_time.*' => [
                'required','string',
               // 'required_unless:task_recurrence,noRepeat',
            ],

            'audio' => 'nullable|file',
            'sub_employee_tasks' =>['nullable', 'array'],
            'sub_employee_tasks.*.name' => ['required', 'string', 'max:255'],
            'sub_employee_tasks.*.description' => ['nullable', 'string'],
            'sub_employee_tasks.*.is_forced_to_upload_img' => ['boolean','in:0,1'],
            'sub_employee_tasks.*.proof_media_type' => ['nullable', 'string', 'in:none,image,video,both'],
            'sub_employee_tasks.*.admin_subtask__img' => ['nullable', 'array'],
            'sub_employee_tasks.*.admin_subtask__img.*' => ['nullable'],



            'admin_img' => ['nullable', 'array'],
            'admin_img.*' => [
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,gif,webp,bmp,mp4,mov,avi,webm,mkv,m4v,3gp',
                'max:102400',
            ],
            'reminder_before_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'reminder_channel' => ['nullable', 'string', 'in:push,email'],
            'reminder_when' => ['nullable', 'string', 'in:none,at_time,before_10m,before_1h,before_1d'],

        ]);

        $data['not_shown_for_employee'] = $request->boolean('not_shown_for_employee');
        $data['is_forced_to_upload_img'] = $request->boolean('is_forced_to_upload_img');
        $data['proof_media_type'] = $this->proofMediaTypeFromInput($request, 'proof_media_type', $data['is_forced_to_upload_img']);
        $data['requires_admin_review'] = $request->boolean('requires_admin_review', true);
        
        if ($request->hasFile('audio')) {
            $audio = $request->file('audio');
            $audioName = $audio->getClientOriginalName();
            $audio->move(public_path('employeeTasksAudio'), $audioName);

            $data['audio'] = $audioName;
        }

       $adminImages= $this->mediaHelper($request,'AdminEmployeeTasksImages');
       $data['admin_img'] = $adminImages;

        if($request->task_recurrence === 'daily'){
            $data['task_recurrence_time'] = ['saturday','sunday','monday','tuesday','wednesday','thursday','friday'];
        }
        elseif($request->task_recurrence === 'monthly'){
            // Automatically get the day of the month from start_time
            $startDay = (int) \Carbon\Carbon::parse($request->start_time)->format('d');
            $data['task_recurrence_time'] = [(string) $startDay];
        }


        elseif($request->task_recurrence === 'weekly'){
            if(!$request->task_recurrence_time){
                return response()->json([
                    'status'=>'error',
                    'message'=>__('messages.enter_recurrence_time')
                ],200);
            }
        }
        $start = \Carbon\Carbon::parse($data['start_time']);
        $end = \Carbon\Carbon::parse($data['end_time']);
        if ($end->lte($start)) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.end_time_must_be_after_start'),
            ], 200);
        }

        $data['status'] = EmployeeTaskStatus::Pending->value;
        $data['priority'] = $request->input('priority', 'medium');

        $reminderMinutes = \App\Support\TaskReminderConfig::minutesFromRequest($request);
        $reminderChannel = \App\Support\TaskReminderConfig::channelFromRequest($request);

        $assigneeService = app(EmployeeTaskAssigneeService::class);
        $assigneeIdsForRoute = $assigneeService->resolveAssigneeIdsFromRequest(
            $request,
            (int) ($data['employee_id'] ?? 0)
        );

        if ($request->boolean('use_v2_recurrence')) {
            return app(EmployeeTaskOperationsController::class)->createWithTemplate($request);
        }

        if (
            $request->task_recurrence !== 'noRepeat'
            && self::activeRecurringTemplateExists($assigneeIdsForRoute, $data['name'])
        ) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.recurring_template_already_exists'),
            ], 200);
        }

        if ($reminderMinutes !== null) {
            $data['reminder_before_minutes'] = $reminderMinutes;
            $data['reminder_channel'] = $reminderChannel;
        }

        $assigneeIds = $assigneeIdsForRoute;
        if ($assigneeIds !== []) {
            $data['employee_id'] = $assigneeIds[0];
        }
        unset($data['employee_ids']);

        $employeeTask = EmployeeTask::create($data);
        $employeeTask->purgeOrphanSubtasks();
        $assigneeService->syncForTask(
            $employeeTask,
            $assigneeIds !== [] ? $assigneeIds : [(int) $employeeTask->employee_id]
        );
        $this->timeline->recordForTask($employeeTask, \App\Models\EmployeeTaskTimeline::EVENT_CREATED);

        if ($request->has('sub_employee_tasks')) {
                foreach ($request->sub_employee_tasks as $index => $subTask) {
                        $subImagesNames = SubtaskAdminMediaStorage::collectFromRequest($request, (int) $index);

                        $subCreate = [
                            'name' => $subTask['name'],
                            'description' => $subTask['description'] ?? null,
                            'employee_task_id' => $employeeTask->id,
                            'is_forced_to_upload_img' => $subTask['is_forced_to_upload_img'] ?? 0,
                            'proof_media_type' => $this->proofMediaTypeFromInput(
                                $subTask,
                                'proof_media_type',
                                filter_var($subTask['is_forced_to_upload_img'] ?? false, FILTER_VALIDATE_BOOLEAN)
                            ),
                            'bonus_points' => (int) ($subTask['bonus_points'] ?? 0),
                            'status' => 'pending',
                            'admin_img' => $subImagesNames,
                        ];
                        if (Schema::hasColumn('sub_employee_tasks', 'sort_order')) {
                            $subCreate['sort_order'] = $index;
                        }
                        EmployeeSubTask::create($subCreate);
                    }
        }

        if (in_array($request->task_recurrence, ['daily', 'weekly', 'monthly'], true) && count($assigneeIds) <= 1) {
            self::createHelper($employeeTask, $request->task_recurrence);
        }

        app(EmployeeTaskNotificationService::class)->notifyAssignedToEmployeeIds(
            $employeeTask->fresh(),
            $assigneeIds !== [] ? $assigneeIds : [(int) $employeeTask->employee_id],
            $employeeTask->occurrence_id
        );

        Logs::createLog('اضافة مهمة موظف','تم اضافة مهمة موظف باسم'.' '.$employeeTask->name
        
        .' '.'تابعة للموظف'.' '.$employeeTask->employee->user->name
        
        ,'employee_tasks');


            $employeeTask->load(['subTasks' => fn ($q) => $q->orderBy('sort_order')]);

            return response()->json([
                'status' => 'success',
                'message' => __('messages.employee_task_created_successfully'),
                'employee_task_id' => $employeeTask->id,
                'subtasks_count' => $employeeTask->subTasks->count(),
                'subtasks' => $employeeTask->subTasks->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'employee_task_id' => $s->employee_task_id,
                ])->values()->all(),
            ], 200);
    }
    catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors()
            ], 200);
        
    }

                catch(QueryException $e){
               return response([
                'status'=>'error',
                'message' => __('messages.create_data_error'),
            ],200);
        }

        catch (\Exception $e) {
             return response()->json([
                'status' => 'error',
                'message' => __('messages.failed_to_create_task'),
            ], 200);        }
    }

    public function showEmployeeTaskDetails(Request $request){
        try{
        $request->validate([
            'occurrence_id' => 'nullable|exists:employee_task_occurrences,id',
            'employee_task_id' => [
                Rule::requiredIf(fn () => ! $request->filled('occurrence_id')),
                'nullable',
                'integer',
                Rule::exists('employee_tasks', 'id'),
            ],
            'task_date' => 'nullable|date',
        ]);

        if (! $request->employee_task_id && ! $request->occurrence_id) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => ['employee_task_id' => ['Task id is required']],
            ], 200);
        }

        $details = app(EmployeeTaskDetailsService::class);
        $photo = fn ($employee) => $this->employeeProfilePhoto($employee);

        if ($request->filled('employee_task_id') && ! $request->filled('occurrence_id')) {
            $employeeTask = EmployeeTask::findOrFail($request->employee_task_id);
            $legacyDay = app(\App\Services\EmployeeTasks\EmployeeLegacyDayInstanceService::class);
            $taskDate = $legacyDay->parseTaskDate($request->input('task_date'), $employeeTask);
            $resolvedTask = $legacyDay->resolveForDate($employeeTask, $taskDate);
            $taskData = $details->formatLegacy($resolvedTask, $photo);
        } elseif ($request->filled('occurrence_id')) {
            $occurrence = EmployeeTaskOccurrence::findOrFail($request->occurrence_id);
            if ($request->filled('employee_task_id')) {
                $legacyId = (int) $request->employee_task_id;
                $linkedLegacy = (int) ($occurrence->legacy_task_id ?? 0);
                if ($linkedLegacy > 0 && $linkedLegacy !== $legacyId) {
                    $employeeTask = EmployeeTask::findOrFail($legacyId);
                    $taskData = $details->formatLegacy($employeeTask, $photo);
                } else {
                    $taskData = $details->formatOccurrence($occurrence, $photo);
                }
            } else {
                $taskData = $details->formatOccurrence($occurrence, $photo);
            }
        } else {
            $employeeTask = EmployeeTask::findOrFail($request->employee_task_id);
            $legacyDay = app(\App\Services\EmployeeTasks\EmployeeLegacyDayInstanceService::class);
            $taskDate = $legacyDay->parseTaskDate($request->input('task_date'), $employeeTask);
            $resolvedTask = $legacyDay->resolveForDate($employeeTask, $taskDate);
            $taskData = $details->formatLegacy($resolvedTask, $photo);
        }

            return response([
                'status' => 'success',
                'employee_task'=>$taskData,

            ],200);
       


    }

    catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.employee_task_not_found')], 200);
        }

    catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);



   }

       catch (\Exception $e) {
            \Log::error('showEmployeeTaskDetails failed', [
                'message' => $e->getMessage(),
                'occurrence_id' => $request->input('occurrence_id'),
                'employee_task_id' => $request->input('employee_task_id'),
                'trace' => $e->getTraceAsString(),
            ]);

             return response()->json([
                'status' => 'error',
                'message' => __('messages.failed_to_fetch_task_details'),
            ], 200);        }

    }


public function updateEmployeeTask(Request $request)
{
    try {
        if ($request->filled('occurrence_id') && ! $request->filled('template_id')) {
            $occ = EmployeeTaskOccurrence::find($request->occurrence_id);
            if ($occ) {
                $request->merge(['template_id' => $occ->template_id]);
            }
        }

        $assigneeService = app(EmployeeTaskAssigneeService::class);

        if (
            ($request->boolean('use_v2_recurrence') || $request->filled('template_id'))
        ) {
            return app(EmployeeTaskOperationsController::class)->updateWithTemplate($request);
        }

        $data = $request->validate([
            'employee_task_id'=>['required','exists:employee_tasks,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'employee_id' => ['required_without:employee_ids','exists:employee_details,id'],
            'employee_ids' => ['nullable', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'exists:employee_details,id'],
            'points' => ['required', 'integer', 'min:0'],
            'start_time' => ['required', 'date', 'before_or_equal:end_time'],
            'end_time' => ['required', 'date', 'after_or_equal:start_time'],
            'task_recurrence' => ['required', 'string','in:noRepeat,oneTimePersistent,daily,weekly,monthly'],
            'proof_media_type' => ['nullable', 'string', 'in:none,image,video,both'],

            'task_recurrence_time' => [
                'nullable',
                'array',
               // 'required_unless:task_recurrence,noRepeat',
            ],
            'task_recurrence_time.*' => [
                
                'required','string',
                //'required_unless:task_recurrence,noRepeat',
            ],
            'admin_img' => ['nullable', 'array'],
            'admin_img.*' => ['nullable'],

            'sub_employee_tasks' => ['nullable', 'array'],
            'sub_employee_tasks.*.id' => ['nullable', 'exists:sub_employee_tasks,id'],
            'sub_employee_tasks.*.name' => ['nullable', 'string', 'max:255'],
            'sub_employee_tasks.*.description' => ['nullable', 'string'],
            'sub_employee_tasks.*.is_forced_to_upload_img' => ['nullable', 'in:0,1,true,false'],
            'sub_employee_tasks.*.proof_media_type' => ['nullable', 'string', 'in:none,image,video,both'],
            'sub_employee_tasks.*.admin_subtask__img' => ['nullable', 'array'],
            'sub_employee_tasks.*.admin_subtask__img.*' => ['nullable'],
 
            'audio' => 'nullable',
            'reminder_before_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'reminder_channel' => ['nullable', 'string', 'in:push,email'],
            'reminder_when' => ['nullable', 'string', 'in:none,at_time,before_10m,before_1h,before_1d'],

        ]);

        $start = \Carbon\Carbon::parse($data['start_time']);
        $end = \Carbon\Carbon::parse($data['end_time']);
        if ($end->lte($start)) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.end_time_must_be_after_start'),
            ], 200);
        }

        // ✅ Always update the parent if the task is a recurrence
        $employeeTask = EmployeeTask::findOrFail($request->employee_task_id);
        if ($employeeTask->parent_id) {
            $employeeTask = EmployeeTask::findOrFail($employeeTask->parent_id);
        }

        $oldAssigneeIds = $assigneeService->idsForTask($employeeTask);
        $finalData = $request->except(['employee_task_id','sub_employee_tasks']);
        unset(
            $finalData['status'],
            $finalData['completed_by_employee_id'],
            $finalData['employee_img'],
            $finalData['started_at'],
            $finalData['submitted_at'],
            $finalData['reviewed_at'],
            $finalData['rejection_notes']
        );
        $finalData['not_shown_for_employee'] = $request->boolean('not_shown_for_employee');
        $finalData['is_forced_to_upload_img'] = $request->boolean('is_forced_to_upload_img');
        $finalData['proof_media_type'] = $this->proofMediaTypeFromInput($request, 'proof_media_type', $finalData['is_forced_to_upload_img']);

        $reminderMinutes = \App\Support\TaskReminderConfig::minutesFromRequest($request);
        $reminderChannel = \App\Support\TaskReminderConfig::channelFromRequest($request);
        if ($reminderMinutes !== null) {
            $finalData['reminder_before_minutes'] = $reminderMinutes;
            $finalData['reminder_channel'] = $reminderChannel;
        } else {
            $finalData['reminder_before_minutes'] = null;
            $finalData['reminder_channel'] = null;
        }

        if ($employeeTask->template_id) {
            $template = EmployeeTaskTemplate::find($employeeTask->template_id);
            if ($template) {
                $config = \App\Support\TaskReminderConfig::mergeIntoRecurrenceConfig(
                    array_merge($template->recurrence_config ?? [], [
                        'start_time' => $data['start_time'],
                        'end_time' => $data['end_time'],
                    ]),
                    $reminderMinutes,
                    $reminderChannel
                );
                $template->update([
                    'name' => $data['name'],
                    'description' => $data['description'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'points' => $data['points'],
                    'is_forced_to_upload_img' => $finalData['is_forced_to_upload_img'],
                    'proof_media_type' => $finalData['proof_media_type'],
                    'recurrence_config' => $config,
                ]);
            }
        }

        if($request->task_recurrence === 'daily'){
            $finalData['task_recurrence_time'] = ['saturday','sunday','monday','tuesday','wednesday','thursday','friday'];
        }
        elseif($request->task_recurrence === 'monthly'){
            // Automatically get the day of the month from start_time
            $startDay = (int) \Carbon\Carbon::parse($request->start_time)->format('d');
            $finalData['task_recurrence_time'] = [(string) $startDay];
        }


        elseif($request->task_recurrence === 'weekly'){
            if(!$request->task_recurrence_time){
                return response()->json([
                    'status'=>'error',
                    'message'=>__('messages.enter_recurrence_time')
                ],200);
            }
        }

       // $finalData['admin_img'] = CommonUse::handleImageUpdate($request,'admin_img','AdminEmployeeTasksImages',$employeeTask->admin_img??[]);
        $oldRecurrence = $employeeTask->task_recurrence;

        $adminUpdatedImages = CommonUse::handleImageUpdate($request,'admin_img','AdminEmployeeTasksImages',$employeeTask->admin_img);
        $finalData['admin_img'] = $adminUpdatedImages;

        if($request->audio){
            if(is_string($request->audio)){
                $finalData['audio'] = $employeeTask->audio??null;
            }
            elseif($request->hasFile('audio')){
                $audio = $request->file('audio');
                $audioName = $audio->getClientOriginalName();
                $audio->move(public_path('employeeTasksAudio'), $audioName);

                $finalData['audio'] = $audioName;
  
            }
        }

        $assigneeIds = app(EmployeeTaskAssigneeService::class)->resolveAssigneeIdsFromRequest(
            $request,
            (int) $request->input('employee_id', 0)
        );
        if ($assigneeIds !== []) {
            $finalData['employee_id'] = $assigneeIds[0];
        }
        unset($finalData['employee_ids']);

        $shouldResetCompletion = $this->shouldResetCompletionForReassignment(
            $employeeTask,
            $oldAssigneeIds,
            $assigneeIds !== [] ? $assigneeIds : [(int) $employeeTask->employee_id]
        );

        if ($shouldResetCompletion) {
            $finalData['status'] = EmployeeTaskStatus::Pending->value;
            $finalData['completed_by_employee_id'] = null;
            $finalData['employee_img'] = null;
            $finalData['started_at'] = null;
            $finalData['submitted_at'] = null;
            $finalData['reviewed_at'] = null;
            $finalData['rejection_notes'] = null;
        }

        $employeeTask->update($finalData);

        app(EmployeeTaskAssigneeService::class)->syncForTaskAndNotifyNewAssignees(
            $employeeTask->fresh(),
            $assigneeIds !== [] ? $assigneeIds : [(int) $employeeTask->employee_id],
            $employeeTask->occurrence_id
        );

        if ($this->taskSeriesNeedsAssigneeSync(
            $employeeTask->fresh(),
            $oldAssigneeIds,
            $assigneeIds !== [] ? $assigneeIds : [(int) $employeeTask->employee_id]
        )) {
            $this->syncTaskSeriesAfterReassignment(
                $employeeTask->fresh(),
                $assigneeIds !== [] ? $assigneeIds : [(int) $employeeTask->employee_id]
            );
        }

        if ($request->has('sub_employee_tasks')) {

            $empT = EmployeeTask::findOrFail($request->employee_task_id);
            
                $existingSubTaskIds = $empT->subTasks()->pluck('id')->toArray();
                $sentSubTasks = $data['sub_employee_tasks'];
                $keepIds = [];

                foreach ($sentSubTasks as $index => $subTaskData) {
                    if (isset($subTaskData['id'])) {
                        $subTask = EmployeeSubTask::find($subTaskData['id']);
                        if ($subTask && (int) $subTask->employee_task_id === (int) $empT->id) {
                            $updatePayload = [];
                            if (Schema::hasColumn('sub_employee_tasks', 'sort_order')) {
                                $updatePayload['sort_order'] = $index;
                            }
                            if (isset($subTaskData['name'])) {
                                $updatePayload['name'] = $subTaskData['name'];
                            }
                            if (array_key_exists('description', $subTaskData)) {
                                $updatePayload['description'] = $subTaskData['description'];
                            }
                            if (isset($subTaskData['is_forced_to_upload_img'])) {
                                $updatePayload['is_forced_to_upload_img'] = $subTaskData['is_forced_to_upload_img'];
                                $updatePayload['proof_media_type'] = $this->proofMediaTypeFromInput(
                                    $subTaskData,
                                    'proof_media_type',
                                    filter_var($subTaskData['is_forced_to_upload_img'], FILTER_VALIDATE_BOOLEAN)
                                );
                            } elseif (array_key_exists('proof_media_type', $subTaskData)) {
                                $updatePayload['proof_media_type'] = $this->proofMediaTypeFromInput(
                                    $subTaskData,
                                    'proof_media_type',
                                    (bool) $subTask->is_forced_to_upload_img
                                );
                            }

                            $subImagesNames = $subTask->admin_img ?? [];
                            $uploaded = SubtaskAdminMediaStorage::collectFromRequest(
                                $request,
                                (int) $index
                            );
                            if ($uploaded !== []) {
                                $subImagesNames = array_merge($subImagesNames, $uploaded);
                                $updatePayload['admin_img'] = $subImagesNames;
                            }

                            $subTask->update($updatePayload);
                            $keepIds[] = $subTask->id;
                        }
                    } else {
                        $subImagesNames = SubtaskAdminMediaStorage::collectFromRequest(
                            $request,
                            (int) $index
                        );
                        // New subtask → create
                        $newSubPayload = [
                            'employee_task_id' => $empT->id,
                            'name' => $subTaskData['name'],
                            'description' => $subTaskData['description'] ?? null,
                            'is_forced_to_upload_img' => $subTaskData['is_forced_to_upload_img'],
                            'proof_media_type' => $this->proofMediaTypeFromInput(
                                $subTaskData,
                                'proof_media_type',
                                filter_var($subTaskData['is_forced_to_upload_img'] ?? false, FILTER_VALIDATE_BOOLEAN)
                            ),
                            'admin_img' => $subImagesNames,
                        ];
                        if (Schema::hasColumn('sub_employee_tasks', 'sort_order')) {
                            $newSubPayload['sort_order'] = $index;
                        }
                        $newSubTask = EmployeeSubTask::create($newSubPayload);
                        $keepIds[] = $newSubTask->id;
                    }
                }

                // Delete subtasks not included
                $deleteIds = array_diff($existingSubTaskIds, $keepIds);
                if (!empty($deleteIds)) {
                    EmployeeSubTask::whereIn('id', $deleteIds)->delete();
                }

              $updatedSubTasks = $empT->subTasks()->get();

              if(!$empT->parent_id){
                // // ✅ Replicate the updated subtasks to all recurrences
                // $recurrenceTasks = EmployeeTask::where('parent_id', $empT->id)->get();

                // foreach ($recurrenceTasks as $childTask) {
                //     // Delete old subtasks for that recurrence
                //     EmployeeSubTask::where('employee_task_id', $childTask->id)->delete();

                //     // Recreate new ones identical to the parent's subtasks
                //     foreach ($updatedSubTasks as $subTask) {
                //         $newSub = $subTask->replicate()->toArray();
                //         $newSub['employee_task_id'] = $childTask->id;
                //         EmployeeSubTask::create($newSub);
                //     }
                // }
            }
            else{
                $parent = EmployeeTask::findOrFail($empT->parent_id);
                $parent->subTasks()->delete();
                foreach ($updatedSubTasks as $subTask) {
                        $newSub = $subTask->replicate()->toArray();
                        $newSub['employee_task_id'] = $parent->id;
                        EmployeeSubTask::create($newSub);
                    }  
                // $children = EmployeeTask::whereNotIn('id',[$empT->id])->where('parent_id',$parent->id)->get();
                // foreach($children as $child){
                //     $child->subTasks()->delete();
                //     foreach ($updatedSubTasks as $subTask) {
                //         $newSub = $subTask->replicate()->toArray();
                //         $newSub['employee_task_id'] = $child->id;
                //         EmployeeSubTask::create($newSub);
                //     }                      
                // }
            }
        }

        if ($shouldResetCompletion) {
            $this->resetEmployeeTaskSubtasksCompletion($employeeTask->fresh());
        }

        $newRecurrence = $finalData['task_recurrence'] ?? $employeeTask->task_recurrence;
        $recurrenceTimesChanged = json_encode($employeeTask->task_recurrence_time ?? [])
            !== json_encode($finalData['task_recurrence_time'] ?? $employeeTask->task_recurrence_time ?? []);
        $shouldRebuildChildren = in_array($newRecurrence, ['daily', 'weekly', 'monthly'], true)
            && ($oldRecurrence !== $newRecurrence || $recurrenceTimesChanged);

        if ($shouldRebuildChildren) {
            EmployeeTask::where('parent_id', $employeeTask->id)->delete();
            $this->createHelper($employeeTask->fresh(), $newRecurrence);
        }

        $employeeTask->refresh();
        $employeeTask->load('employee.user');
        $employeeName = $employeeTask->employee?->user?->name ?? '';

        Logs::createLog(
            'تعديل مهمة موظف',
            'تم تعديل مهمة الموظف باسم ' . $employeeTask->name
            .' '.'التابعة للموظف'.' '.$employeeName
             
            ,
            'employee_tasks'
        );

        return response()->json([
            'status' => 'success',
            'message' => __('messages.employee_task_updated_successfully')
        ], 200);

    } catch (ValidationException $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.validation_failed'),
            'errors' => $e->errors()
        ], 200);

    } catch (ModelNotFoundException $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.something_wrong'),
                        'e'=>$e->getMessage(),

        ], 200);

    } catch (QueryException $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.update_data_error'),
        ], 200);

    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.something_wrong'),
                        'e'=>$e->getMessage(),

        ], 200);
    }
}

    public function changeEmployeeTaskToCompleted(Request $request){
        try{
        $request->validate([
            'employee_task_id'=>'required|exists:employee_tasks,id',
            'employee_notes' => 'nullable|string',
            'task_date' => 'nullable|date',
        ]);

        $legacyDay = app(EmployeeLegacyDayInstanceService::class);
        $requested = EmployeeTask::findOrFail($request->employee_task_id);
        $taskDate = $legacyDay->parseTaskDate($request->input('task_date'), $requested);
        $task = $legacyDay->resolveForDate($requested, $taskDate);
        $parentTemplate = ! empty($requested->parent_id)
            ? EmployeeTask::find($requested->parent_id)
            : ($legacyDay->isRecurringParent($requested) ? $requested : null);

        $user = auth()->user();
        $isManager = $user && ! $user->employee;
        $actorEmployeeId = (int) ($user?->employee?->id ?? 0);
        $assignees = app(EmployeeTaskAssigneeService::class);

        if (! $isManager && $actorEmployeeId > 0 && ! $assignees->isAssignee($task, $actorEmployeeId)) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.unauthorized'),
            ], 200);
        }

        if (! $isManager
            && $task->completed_by_employee_id
            && (int) $task->completed_by_employee_id !== $actorEmployeeId) {
            $task->loadMissing('completedByEmployee.user');
            $name = $task->completedByEmployee?->user?->name ?? __('messages.employee');

            return response()->json([
                'status' => 'error',
                'message' => __('messages.task_completed_by_other', ['name' => $name]),
            ], 200);
        }

        if ($task->status === EmployeeTaskStatus::WaitingReview->value && $isManager) {
            $task = $this->workflow->approveTask($task);
            try {
                app(\App\Services\AdminNotificationService::class)->notifyTaskCompleted($task->employee, $task);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Admin notification: '.$e->getMessage());
            }

            return response()->json(['status' => 'success', 'message' => __('messages.task_completed')], 200);
        }

        if (! $isManager) {
            if ($task->status === EmployeeTaskStatus::Pending->value || $task->status === EmployeeTaskStatus::Overdue->value) {
                $this->workflow->startTask($task);
                $task->refresh();
            }
            $this->workflow->submitTaskForReview($task, $request->employee_notes);
            if ($parentTemplate) {
                $legacyDay->keepParentTemplateActive($parentTemplate, $task);
            }
            Logs::createLog('تسليم مهمة للمراجعة', 'تسليم مهمة باسم '.$task->name, 'employee_tasks');
            app(EmployeeActivityLogger::class)->log(
                (int) $actorEmployeeId,
                $user,
                'tasks',
                'submitted_employee_task',
                'تسليم مهمة للمراجعة',
                'تم تسليم مهمة باسم '.$task->name.' للمراجعة',
                $task,
                null,
                ['task_name' => $task->name, 'task_date' => $taskDate?->format('Y-m-d')]
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.task_submitted_for_review'),
            ], 200);
        }

        $task = $this->workflow->approveTask($task);
        Logs::createLog('اكمال مهمة موظف','اكمال مهمة موظف باسم'.' '.$task->name
        .' '.'التابعة للموظف'.' '.$task->employee->user->name,
        'employee_tasks');
        app(EmployeeActivityLogger::class)->log(
            (int) $task->employee_id,
            $user,
            'tasks',
            'completed_employee_task',
            'إكمال مهمة موظف',
            'تم إكمال مهمة باسم '.$task->name,
            $task,
            null,
            ['task_name' => $task->name]
        );

        try {
            app(\App\Services\AdminNotificationService::class)->notifyTaskCompleted($task->employee, $task);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Admin notification (task completed): '.$e->getMessage());
        }

        return response()->json([
            'status' => 'success',
            'message' => __('messages.task_completed'),

        ], 200);
      }
         catch (ModelNotFoundException $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.task_not_found'),
        ], 200);

    } catch (ValidationException $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.validation_failed'),
        ], 200);

    } 
    
    
    catch(QueryException $e){
               return response([
                'status'=>'error',
                'message' => __('messages.something_wrong'),
            ],200);
        }
    
    catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.unexpected_error'),
        ], 200);
    }
    }


        // for employe to do it
    public function changeSubTaskToCompleted(Request $request){
        try{
        $request->validate([
            'sub_task_id'=>'required|exists:sub_employee_tasks,id',
        ]);

        $subTask = EmployeeSubTask::findOrFail($request->sub_task_id);
        $actorId = (int) (auth()->user()->employee->id ?? 0);
        $parent = $subTask->employeeTask;
        if (! app(EmployeeTaskAssigneeService::class)->isAssignee($parent, $actorId)) {
           return response()->json([
                'status' => 'error',
                'message' => __('messages.unauthorized'),
            ], 200);
        }

        if (! \App\Support\TaskMediaFiles::hasRequiredProof(
            $subTask->employee_img,
            $subTask->proof_media_type,
            (bool) $subTask->is_forced_to_upload_img
        )) {
                return response()->json([
                'status' => 'error',
                'message' => __('messages.employee_image_required'),
            ], 200);
            }
        
        $allSubTasks = EmployeeSubTask::where('employee_task_id',$subTask->employee_task_id)
        ->whereNotIn('id',[$request->sub_task_id])
        ->whereNotIn('status', ['completed', 'rejected'])
        ->exists();


        if (! $allSubTasks) {
            $this->workflow->completeSubtask($subTask);

            $employeeTask = EmployeeTask::findOrFail($subTask->employee_task_id)->fresh();

            if ($employeeTask->status === EmployeeTaskStatus::Pending->value
                || $employeeTask->status === EmployeeTaskStatus::Overdue->value) {
                $this->workflow->startTask($employeeTask);
                $employeeTask->refresh();
            }

            // إثبات المهمة الرئيسية منفصل — لا يمنع إكمال آخر فرعية
            if (! \App\Support\TaskMediaFiles::hasRequiredProof(
                $employeeTask->employee_img,
                $employeeTask->proof_media_type,
                (bool) $employeeTask->is_forced_to_upload_img
            )) {
                return response()->json([
                    'status' => 'success',
                    'message' => __('messages.subtask_completed_upload_proof'),
                    'all_subtasks_done' => true,
                ], 200);
            }

            try {
                $this->workflow->submitTaskForReview($employeeTask);
            } catch (\Throwable $e) {
                return response()->json(['status' => 'error', 'message' => $e->getMessage()], 200);
            }
        } else {
            $this->workflow->completeSubtask($subTask);
        }

        Logs::createLog('اكمال مهمة موظف فرعية','اكمال مهمة موظف فرعية باسم'.' '.$subTask->name
        
        .' '.'التابعة للمهمة الرئيسية باسم'.' '.$subTask->employeeTask->name
        
        ,'employee_tasks');
        app(EmployeeActivityLogger::class)->log(
            (int) $actorId,
            auth()->user(),
            'tasks',
            'completed_employee_subtask',
            'إكمال مهمة فرعية',
            'تم إكمال مهمة فرعية باسم '.$subTask->name.' ضمن '.$subTask->employeeTask->name,
            $subTask->employeeTask,
            null,
            ['sub_task_id' => (int) $subTask->id, 'sub_task_name' => $subTask->name]
        );

            return response()->json([
            'status' => 'success',
            'message' => __('messages.task_completed'),

        ], 200);
      }
         catch (ModelNotFoundException $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.task_not_found'),
        ], 200);

    } catch (ValidationException $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.validation_failed'),
        ], 200);

    } 
    
    
    catch(QueryException $e){
               return response([
                'status'=>'error',
                'message' => __('messages.something_wrong'),
            ],200);
        }
    
    catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.unexpected_error'),
        ], 200);
    }
    }

    public function undoSubTaskCompletion(Request $request)
    {
        try {
            $request->validate([
                'sub_task_id' => 'required|exists:sub_employee_tasks,id',
            ]);

            $subTask = EmployeeSubTask::with('employeeTask')->findOrFail($request->sub_task_id);
            $actorId = (int) (auth()->user()->employee->id ?? 0);
            $parent = $subTask->employeeTask;
            if (! $parent || ! app(EmployeeTaskAssigneeService::class)->isAssignee($parent, $actorId)) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.unauthorized'),
                ], 200);
            }

            $this->workflow->undoSubtaskCompletion($subTask);

            Logs::createLog(
                'تراجع عن إنجاز مهمة فرعية',
                'تراجع عن إنجاز مهمة فرعية باسم '.$subTask->name.' التابعة للمهمة '.$parent->name,
                'employee_tasks'
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.subtask_completion_undone'),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage() ?: __('messages.unexpected_error'),
            ], 200);
        }
    }

    public function rejectSubTask(Request $request){
        try{
        $request->validate([
            'sub_task_id'=>'required|exists:sub_employee_tasks,id',
            'rejection_reason'=>'required|string|max:1000',
        ]);

        $subTask = EmployeeSubTask::findOrFail($request->sub_task_id);
        $user = $request->user();
        $actorId = (int) ($user?->employee?->id ?? 0);
        $canReviewEmployeeTasks = $user?->type === 'admin';
        if (! $canReviewEmployeeTasks && $user?->employee) {
            $canReviewEmployeeTasks = (bool) $user->employee->permissions()
                ->whereHas('permission', fn ($q) => $q
                    ->where('name_en', 'Employee Tasks')
                    ->orWhere('id', 7))
                ->exists();
        }
        $parent = $subTask->employeeTask;
        $parentWasWaitingReview = $parent
            && $parent->status === EmployeeTaskStatus::WaitingReview->value;
        if (! $parent || (! app(EmployeeTaskAssigneeService::class)->isAssignee($parent, $actorId) && ! $canReviewEmployeeTasks)) {
           return response()->json([
                'status' => 'error',
                'message' => __('messages.unauthorized'),
            ], 200);
        }

        $returnForRework = str_contains((string) $request->path(), 'admin/change/');
        $this->workflow->rejectSubtask($subTask, $request->rejection_reason, $returnForRework);

        Logs::createLog('رفض مهمة موظف فرعية','رفض تنفيذ مهمة فرعية باسم'.' '.$subTask->name
        .' '.'التابعة للمهمة الرئيسية باسم'.' '.$subTask->employeeTask->name
        .' '.'السبب:'.' '.$request->rejection_reason
        ,'employee_tasks');

        // إذا لم يتبقَّ أي مهمة فرعية بانتظار التنفيذ (الكل مكتمل أو مرفوض)
        // تُسلَّم المهمة الرئيسية تلقائياً للمراجعة.
        $pendingRemains = EmployeeSubTask::where('employee_task_id', $subTask->employee_task_id)
            ->whereNotIn('status', ['completed', 'rejected'])
            ->exists();

        if (! $pendingRemains && ! $parentWasWaitingReview) {
            $employeeTask = EmployeeTask::findOrFail($subTask->employee_task_id)->fresh();

            if ($employeeTask->status === EmployeeTaskStatus::Pending->value
                || $employeeTask->status === EmployeeTaskStatus::Overdue->value) {
                $this->workflow->startTask($employeeTask);
                $employeeTask->refresh();
            }

            // إثبات المهمة الرئيسية منفصل — إن كان مطلوباً ولم يُرفع، ننتظر رفعه ثم التسليم.
            if (! \App\Support\TaskMediaFiles::hasRequiredProof(
                $employeeTask->employee_img,
                $employeeTask->proof_media_type,
                (bool) $employeeTask->is_forced_to_upload_img
            )) {
                return response()->json([
                    'status' => 'success',
                    'message' => __('messages.subtask_completed_upload_proof'),
                    'all_subtasks_done' => true,
                ], 200);
            }

            try {
                $this->workflow->submitTaskForReview($employeeTask);
            } catch (\Throwable $e) {
                return response()->json(['status' => 'error', 'message' => $e->getMessage()], 200);
            }
        }

            return response()->json([
            'status' => 'success',
            'message' => __('messages.subtask_rejected'),
        ], 200);
      }
         catch (ModelNotFoundException $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.task_not_found'),
        ], 200);

    } catch (ValidationException $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.validation_failed'),
        ], 200);

    }

    catch(QueryException $e){
               return response([
                'status'=>'error',
                'message' => __('messages.something_wrong'),
            ],200);
        }

    catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => __('messages.unexpected_error'),
        ], 200);
    }
    }

}




//     public function getCompletedTasks()
// {
//     $tasks = EmployeeTask::with('user')
//         ->where('status', 'completed')
//         ->get(['id', 'name', 'user_id', 'start_time', 'end_time']);
    

//     dd($tasks);
    
//     }
