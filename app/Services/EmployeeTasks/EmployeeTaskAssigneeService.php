<?php

namespace App\Services\EmployeeTasks;

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
        $ids = collect($request->input('employee_ids', []))
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
}
