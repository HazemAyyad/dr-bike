<?php

namespace App\Services\EmployeeTasks;

use App\Models\EmployeeDetail;
use App\Models\EmployeeTask;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EmployeeTaskAssigneeService
{
    /**
     * @return array<int>
     */
    public function resolveAssigneeIdsFromRequest(\Illuminate\Http\Request $request, int $fallbackEmployeeId): array
    {
        $raw = $request->input('employee_ids');

        if (! is_array($raw)) {
            $raw = [];
        }

        if ($raw === []) {
            foreach ($request->all() as $key => $value) {
                $key = (string) $key;
                if ($key === 'employee_ids[]' || preg_match('/^employee_ids\[\d*\]$/', $key)) {
                    if (is_array($value)) {
                        $raw = array_merge($raw, $value);
                    } elseif ($value !== null && $value !== '') {
                        $raw[] = $value;
                    }
                }
            }
        }

        $ids = collect($raw)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty() && $fallbackEmployeeId > 0) {
            $ids->push($fallbackEmployeeId);
        }

        return $ids->all();
    }

    /**
     * @param  array<int|string>  $employeeIds
     */
    public function syncForTask(EmployeeTask $task, array $employeeIds): void
    {
        if (! Schema::hasTable('employee_task_assignees')) {
            return;
        }

        $ids = collect($employeeIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            $ids = collect([(int) $task->employee_id])->filter(fn ($id) => $id > 0);
        }

        DB::table('employee_task_assignees')->where('employee_task_id', $task->id)->delete();

        $now = now();
        foreach ($ids as $employeeId) {
            DB::table('employee_task_assignees')->insert([
                'employee_task_id' => $task->id,
                'employee_id' => $employeeId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Sync assignees and notify only newly added employees (e.g. on task edit).
     *
     * @param  array<int|string>  $employeeIds
     */
    public function syncForTaskAndNotifyNewAssignees(
        EmployeeTask $task,
        array $employeeIds,
        ?int $occurrenceId = null
    ): void {
        $oldIds = $this->idsForTask($task);

        $newIds = collect($employeeIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($newIds === []) {
            $newIds = [(int) $task->employee_id];
        }

        $this->syncForTask($task, $newIds);

        $addedIds = array_values(array_diff($newIds, $oldIds));

        if ($addedIds === []) {
            return;
        }

        app(EmployeeTaskNotificationService::class)->notifyAssignedToEmployeeIds(
            $task->fresh(),
            $addedIds,
            $occurrenceId ?? $task->occurrence_id
        );
    }

    public function copyFromParent(Model $parent, Model $child): void
    {
        if (! Schema::hasTable('employee_task_assignees') || ! $parent instanceof EmployeeTask || ! $child instanceof EmployeeTask) {
            return;
        }

        $rows = DB::table('employee_task_assignees')
            ->where('employee_task_id', $parent->id)
            ->get();

        if ($rows->isEmpty()) {
            $this->syncForTask($child, [(int) $child->employee_id]);

            return;
        }

        $now = now();
        foreach ($rows as $row) {
            DB::table('employee_task_assignees')->insert([
                'employee_task_id' => $child->id,
                'employee_id' => $row->employee_id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function isAssignee(EmployeeTask $task, int $employeeId): bool
    {
        if ((int) $task->employee_id === $employeeId) {
            return true;
        }

        if (! Schema::hasTable('employee_task_assignees')) {
            return false;
        }

        return DB::table('employee_task_assignees')
            ->where('employee_task_id', $task->id)
            ->where('employee_id', $employeeId)
            ->exists();
    }

    /**
     * @return array<int>
     */
    public function idsForTask(EmployeeTask $task): array
    {
        if (! Schema::hasTable('employee_task_assignees')) {
            return [(int) $task->employee_id];
        }

        $ids = DB::table('employee_task_assignees')
            ->where('employee_task_id', $task->id)
            ->pluck('employee_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $ids !== [] ? $ids : [(int) $task->employee_id];
    }

    /**
     * @return array<int, array{id: int, name: string, photo: string}>
     */
    public function profilesForTask(EmployeeTask $task, callable $photoResolver): array
    {
        $ids = $this->idsForTask($task);

        $employees = EmployeeDetail::with('user')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $profiles = [];
        foreach ($ids as $employeeId) {
            $employee = $employees->get($employeeId);
            if (! $employee) {
                continue;
            }
            $profiles[] = [
                'id' => (int) $employee->id,
                'name' => $employee->user?->name ?? '',
                'photo' => $photoResolver($employee),
            ];
        }

        return $profiles;
    }
}
