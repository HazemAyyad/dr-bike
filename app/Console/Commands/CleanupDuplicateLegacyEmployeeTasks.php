<?php

namespace App\Console\Commands;

use App\Models\EmployeeTask;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CleanupDuplicateLegacyEmployeeTasks extends Command
{
    protected $signature = 'employee-tasks:cleanup-duplicate-legacy
                            {--parent=7650 : Legacy parent task id to cancel with all children}
                            {--fix-assignee-task=331 : Task id with wrong co-assignee}
                            {--wrong-employee=10 : Co-assignee employee id to remove}
                            {--dry-run : Preview changes without writing}';

    protected $description = 'Cancel a duplicate legacy recurring series and fix stray co-assignee rows';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $parentId = (int) $this->option('parent');
        $fixTaskId = (int) $this->option('fix-assignee-task');
        $wrongEmployeeId = (int) $this->option('wrong-employee');

        if ($dryRun) {
            $this->warn('Dry run — no database writes.');
        }

        $this->cancelLegacySeries($parentId, $dryRun);
        $this->removeWrongAssignee($fixTaskId, $wrongEmployeeId, $dryRun);

        return self::SUCCESS;
    }

    private function cancelLegacySeries(int $parentId, bool $dryRun): void
    {
        $parent = EmployeeTask::query()->find($parentId);

        if (! $parent) {
            $this->warn("Parent task #{$parentId} not found — skipping series cancel.");

            return;
        }

        $childIds = EmployeeTask::query()
            ->where('parent_id', $parentId)
            ->pluck('id');

        $this->info("Parent #{$parentId}: \"{$parent->name}\" (employee {$parent->employee_id})");
        $this->info('Children to cancel: '.$childIds->count());

        if ($dryRun) {
            return;
        }

        EmployeeTask::query()
            ->where('parent_id', $parentId)
            ->update(['is_canceled' => 1]);

        $parent->update(['is_canceled' => 1]);

        $this->info("Canceled parent #{$parentId} and {$childIds->count()} child row(s).");
    }

    private function removeWrongAssignee(int $taskId, int $employeeId, bool $dryRun): void
    {
        if (! Schema::hasTable('employee_task_assignees')) {
            $this->warn('employee_task_assignees table missing — skipping assignee fix.');

            return;
        }

        $query = DB::table('employee_task_assignees')
            ->where('employee_task_id', $taskId)
            ->where('employee_id', $employeeId);

        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info("No assignee row for task #{$taskId} / employee #{$employeeId}.");

            return;
        }

        $this->info("Removing {$count} stray assignee row(s) on task #{$taskId} for employee #{$employeeId}.");

        if ($dryRun) {
            return;
        }

        $query->delete();

        $this->info('Assignee fix applied.');
    }
}
