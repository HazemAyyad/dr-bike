<?php

namespace App\Http\Controllers\API;

use App\Enums\EmployeeTaskStatus;
use App\Http\Controllers\Controller;
use App\Models\EmployeeTask;
use App\Models\EmployeeTaskOccurrence;
use App\Models\EmployeeTaskTemplate;
use App\Models\EmployeeTaskTemplateSubtask;
use App\Models\SpecialTask;
use App\Models\SubTask;
use App\Services\EmployeeTasks\EmployeeTaskRecurrenceService;
use App\Services\EmployeeTasks\EmployeeTaskTimelineService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

class TaskConversionController extends Controller
{
    private const EMPLOYEE_ADMIN_DIR = 'AdminEmployeeTasksImages';
    private const EMPLOYEE_SUBTASK_ADMIN_DIR = 'EmployeeSubTasks/AdminImages';
    private const EMPLOYEE_AUDIO_DIR = 'employeeTasksAudio';
    private const SPECIAL_ADMIN_DIR = 'SpecialTasksAdminImages';
    private const SPECIAL_SUBTASK_ADMIN_DIR = 'SupSpecialTasksAdminImages';
    private const SPECIAL_AUDIO_DIR = 'SpecialTasksAudio';

    public function employeeToSpecial(Request $request)
    {
        try {
            $data = $request->validate([
                'employee_task_id' => 'nullable|exists:employee_tasks,id',
                'occurrence_id' => 'nullable|exists:employee_task_occurrences,id',
            ]);

            if (empty($data['employee_task_id']) && empty($data['occurrence_id'])) {
                throw ValidationException::withMessages([
                    'employee_task_id' => __('messages.validation_failed'),
                ]);
            }

            $result = DB::transaction(function () use ($data) {
                $source = ! empty($data['occurrence_id'])
                    ? EmployeeTaskOccurrence::with(['subtasks', 'template'])->findOrFail($data['occurrence_id'])
                    : EmployeeTask::with(['subTasks', 'template.subtasks'])->findOrFail($data['employee_task_id']);

                $recurrence = $this->specialSupportedRecurrence($this->employeeRecurrence($source));
                $start = Carbon::parse($source->start_time);
                $end = Carbon::parse($source->end_time);

                $special = SpecialTask::create([
                    'name' => $source->name,
                    'description' => $source->description,
                    'notes' => $source->notes,
                    'points' => (int) ($source->points ?? 0),
                    'start_date' => $start,
                    'end_date' => $end,
                    'not_shown_for_employee' => (bool) ($source->not_shown_for_employee ?? false),
                    'task_recurrence' => $recurrence,
                    'task_recurrence_time' => $this->specialRecurrenceTime($source, $recurrence, $start),
                    'status' => EmployeeTaskStatus::normalize($source->status)->value === EmployeeTaskStatus::Completed->value
                        ? 'completed'
                        : 'ongoing',
                    'is_canceled' => 0,
                    'admin_img' => $this->copyMediaList($source->admin_img, self::EMPLOYEE_ADMIN_DIR, self::SPECIAL_ADMIN_DIR),
                    'force_employee_to_add_img' => (bool) ($source->is_forced_to_upload_img ?? false),
                    'audio' => $this->copySingleMedia($source->audio, self::EMPLOYEE_AUDIO_DIR, self::SPECIAL_AUDIO_DIR),
                ]);

                foreach ($this->employeeSubtasks($source) as $index => $subtask) {
                    SubTask::create([
                        'special_task_id' => $special->id,
                        'name' => $subtask->name,
                        'description' => $subtask->description,
                        'status' => $subtask->status === EmployeeTaskStatus::Completed->value ? 'completed' : 'ongoing',
                        'force_employee_to_add_img_for_sub_task' => (bool) ($subtask->requires_image ?? $subtask->is_forced_to_upload_img ?? false),
                        'sort_order' => $subtask->sort_order ?? $index,
                        'admin_img' => $this->copyMediaList($subtask->admin_img, self::EMPLOYEE_SUBTASK_ADMIN_DIR, self::SPECIAL_SUBTASK_ADMIN_DIR),
                    ]);
                }

                $this->archiveEmployeeSource($source);

                Logs::createLog(
                    'تحويل مهمة موظف إلى مهمة خاصة',
                    'تحويل مهمة موظف باسم '.$source->name.' إلى مهمة خاصة',
                    'employee_tasks'
                );

                return $special;
            });

            return response()->json([
                'status' => 'success',
                'message' => 'تم تحويل المهمة إلى مهمة خاصة',
                'special_task_id' => $result->id,
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

    public function specialToEmployee(Request $request, EmployeeTaskRecurrenceService $recurrence)
    {
        try {
            $data = $request->validate([
                'special_task_id' => 'required|exists:special_tasks,id',
                'employee_id' => 'required|exists:employee_details,id',
            ]);

            $result = DB::transaction(function () use ($data, $recurrence) {
                $special = SpecialTask::with('subTasks')->findOrFail($data['special_task_id']);
                $start = Carbon::parse($special->start_date);
                $end = Carbon::parse($special->end_date);
                $type = $this->employeeSupportedRecurrence($special->task_recurrence);

                $template = EmployeeTaskTemplate::create([
                    'employee_id' => (int) $data['employee_id'],
                    'name' => $special->name,
                    'description' => $special->description,
                    'notes' => $special->notes,
                    'points' => (int) ($special->points ?? 0),
                    'priority' => 'medium',
                    'is_forced_to_upload_img' => (bool) ($special->force_employee_to_add_img ?? false),
                    'proof_media_type' => (bool) ($special->force_employee_to_add_img ?? false) ? 'image' : 'none',
                    'requires_admin_review' => true,
                    'not_shown_for_employee' => (bool) ($special->not_shown_for_employee ?? false),
                    'admin_img' => $this->copyMediaList($special->admin_img, self::SPECIAL_ADMIN_DIR, self::EMPLOYEE_ADMIN_DIR),
                    'audio' => $this->copySingleMedia($special->audio, self::SPECIAL_AUDIO_DIR, self::EMPLOYEE_AUDIO_DIR),
                    'recurrence_type' => $type,
                    'recurrence_config' => $this->employeeRecurrenceConfig($special, $type, $start, $end),
                    'time_window_start' => $start->format('H:i:s'),
                    'time_window_end' => $end->format('H:i:s'),
                    'is_active' => true,
                    'created_by' => auth()->id(),
                ]);

                foreach ($special->subTasks as $index => $subtask) {
                    EmployeeTaskTemplateSubtask::create([
                        'template_id' => $template->id,
                        'name' => $subtask->name,
                        'description' => $subtask->description,
                        'sort_order' => $subtask->sort_order ?? $index,
                        'requires_image' => (bool) ($subtask->force_employee_to_add_img_for_sub_task ?? false),
                        'proof_media_type' => (bool) ($subtask->force_employee_to_add_img_for_sub_task ?? false) ? 'image' : 'none',
                        'bonus_points' => 0,
                        'admin_img' => $this->copyMediaList($subtask->admin_img, self::SPECIAL_SUBTASK_ADMIN_DIR, self::EMPLOYEE_SUBTASK_ADMIN_DIR),
                    ]);
                }

                $template->load('subtasks');
                $occurrences = $recurrence->ensureOccurrences($template);

                $special->update(['is_canceled' => 1]);

                Logs::createLog(
                    'تحويل مهمة خاصة إلى مهمة موظف',
                    'تحويل مهمة خاصة باسم '.$special->name.' إلى مهمة موظف',
                    'special_tasks'
                );

                return [
                    'template' => $template,
                    'occurrence' => $occurrences->first(),
                ];
            });

            return response()->json([
                'status' => 'success',
                'message' => 'تم تحويل المهمة إلى مهمة موظف',
                'template_id' => $result['template']->id,
                'occurrence_id' => $result['occurrence']?->id,
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

    private function employeeRecurrence(EmployeeTask|EmployeeTaskOccurrence $source): string
    {
        if ($source instanceof EmployeeTaskOccurrence) {
            return $source->template?->recurrence_type ?? 'noRepeat';
        }

        return $source->template?->recurrence_type ?? $source->task_recurrence ?? 'noRepeat';
    }

    private function specialSupportedRecurrence(?string $recurrence): string
    {
        return in_array($recurrence, ['noRepeat', 'daily', 'weekly', 'monthly'], true)
            ? $recurrence
            : 'noRepeat';
    }

    private function employeeSupportedRecurrence(?string $recurrence): string
    {
        return in_array($recurrence, ['noRepeat', 'daily', 'weekly', 'monthly'], true)
            ? $recurrence
            : 'noRepeat';
    }

    private function specialRecurrenceTime(EmployeeTask|EmployeeTaskOccurrence $source, string $recurrence, Carbon $start): array
    {
        if ($source instanceof EmployeeTask && is_array($source->task_recurrence_time)) {
            return $source->task_recurrence_time;
        }

        $config = $source instanceof EmployeeTaskOccurrence
            ? ($source->template?->recurrence_config ?? [])
            : ($source->template?->recurrence_config ?? []);

        return match ($recurrence) {
            'daily' => ['saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'weekly' => array_values($config['weekdays'] ?? [strtolower($start->format('l'))]),
            'monthly' => array_map('strval', $config['month_days'] ?? [(string) $start->format('d')]),
            default => [],
        };
    }

    private function employeeRecurrenceConfig(SpecialTask $special, string $type, Carbon $start, Carbon $end): array
    {
        $times = is_array($special->task_recurrence_time) ? $special->task_recurrence_time : [];
        $config = [
            'start_time' => $start->toDateTimeString(),
            'end_time' => $end->toDateTimeString(),
            'anchor_date' => $start->toDateString(),
            'duration_type' => 'forever',
        ];

        if ($type === 'weekly') {
            $config['weekdays'] = $times;
        } elseif ($type === 'monthly') {
            $config['monthly_mode'] = 'dates';
            $config['month_days'] = array_map('intval', $times ?: [$start->format('d')]);
        }

        return $config;
    }

    /**
     * @return EloquentCollection<int, mixed>
     */
    private function employeeSubtasks(EmployeeTask|EmployeeTaskOccurrence $source): EloquentCollection
    {
        if ($source instanceof EmployeeTaskOccurrence) {
            return $source->subtasks;
        }

        if ($source->subTasks->isNotEmpty()) {
            return $source->subTasks;
        }

        return $source->template?->subtasks ?? new EloquentCollection();
    }

    private function archiveEmployeeSource(EmployeeTask|EmployeeTaskOccurrence $source): void
    {
        if ($source instanceof EmployeeTaskOccurrence) {
            $source->update([
                'is_canceled' => true,
                'status' => EmployeeTaskStatus::Canceled->value,
            ]);
            if ($source->template && in_array($source->template->recurrence_type, ['noRepeat', EmployeeTaskRecurrenceService::ONE_TIME_PERSISTENT], true)) {
                $source->template->update(['is_active' => false]);
            }
            if ($source->legacy_task_id) {
                EmployeeTask::where('id', $source->legacy_task_id)->update([
                    'is_canceled' => 1,
                    'status' => EmployeeTaskStatus::Canceled->value,
                ]);
            }
            return;
        }

        $source->update([
            'is_canceled' => 1,
            'status' => EmployeeTaskStatus::Canceled->value,
        ]);
        if ($source->template && in_array($source->template->recurrence_type, ['noRepeat', EmployeeTaskRecurrenceService::ONE_TIME_PERSISTENT], true)) {
            $source->template->update(['is_active' => false]);
        }
    }

    private function copyMediaList(mixed $files, string $fromDir, string $toDir): array
    {
        if (! is_array($files)) {
            return [];
        }

        return collect($files)
            ->map(fn ($file) => $this->copySingleMedia((string) $file, $fromDir, $toDir))
            ->filter()
            ->values()
            ->all();
    }

    private function copySingleMedia(?string $file, string $fromDir, string $toDir): ?string
    {
        $file = trim((string) $file);
        if ($file === '' || $file === 'no audio' || $file === 'no images') {
            return null;
        }

        $relative = str_starts_with($file, 'public/')
            ? substr($file, strlen('public/'))
            : trim($fromDir.'/'.$file, '/');

        $source = public_path($relative);
        $name = basename(str_replace('\\', '/', $relative));
        $targetDir = public_path($toDir);
        $target = $targetDir.DIRECTORY_SEPARATOR.$name;

        if (! File::exists($targetDir)) {
            File::makeDirectory($targetDir, 0755, true);
        }

        if (File::exists($source) && ! File::exists($target)) {
            File::copy($source, $target);
        }

        return $name;
    }
}
