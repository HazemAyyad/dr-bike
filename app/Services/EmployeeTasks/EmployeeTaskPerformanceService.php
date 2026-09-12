<?php

namespace App\Services\EmployeeTasks;

use App\Enums\EmployeeTaskStatus;
use App\Models\EmployeeDetail;
use App\Models\EmployeeTask;
use App\Models\EmployeeTaskOccurrence;
use App\Services\EmployeePointsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class EmployeeTaskPerformanceService
{
    public function getPerformance(int $employeeId): array
    {
        $employee = EmployeeDetail::with('user')->findOrFail($employeeId);

        $legacyStats = $this->legacyTaskStats($employeeId);
        $occurrenceStats = $this->occurrenceStats($employeeId);

        $completed = $legacyStats['completed'] + $occurrenceStats['completed'];
        $total = $legacyStats['total'] + $occurrenceStats['total'];
        $overdue = $legacyStats['overdue'] + $occurrenceStats['overdue'];

        return [
            'employee_id' => $employeeId,
            'employee_name' => $employee->user->name ?? '',
            'total_points' => app(EmployeePointsService::class)->getTotalNetPoints($employeeId),
            'streak_days' => $this->calculateStreak($employeeId),
            'completion_rate' => $total > 0 ? round(($completed / $total) * 100, 1) : 0,
            'overdue_count' => $overdue,
            'completed_count' => $completed,
            'total_tasks' => $total,
            'weekly_performance' => $this->periodChart($employeeId, 'week'),
            'monthly_performance' => $this->periodChart($employeeId, 'month'),
            'leaderboard' => $this->leaderboard(10),
            'badges' => $this->badges($employeeId, $completed, $overdue),
        ];
    }

    private function legacyTaskStats(int $employeeId): array
    {
        $base = $this->legacyQueryForEmployee($employeeId)->where('is_canceled', 0);

        return [
            'completed' => (clone $base)->where('status', EmployeeTaskStatus::Completed->value)->count(),
            'overdue' => (clone $base)->where('status', EmployeeTaskStatus::Overdue->value)->count(),
            'total' => (clone $base)->count(),
        ];
    }

    private function occurrenceStats(int $employeeId): array
    {
        if (! Schema::hasTable('employee_task_occurrences')) {
            return ['completed' => 0, 'overdue' => 0, 'total' => 0];
        }

        $base = $this->occurrenceQueryForEmployee($employeeId)->where('is_canceled', 0);

        return [
            'completed' => (clone $base)->where('status', EmployeeTaskStatus::Completed->value)->count(),
            'overdue' => (clone $base)->where('status', EmployeeTaskStatus::Overdue->value)->count(),
            'total' => (clone $base)->count(),
        ];
    }

    private function calculateStreak(int $employeeId): int
    {
        $dates = collect();

        $this->legacyQueryForEmployee($employeeId)
            ->where('status', EmployeeTaskStatus::Completed->value)
            ->whereNotNull('reviewed_at')
            ->pluck('reviewed_at')
            ->each(fn ($d) => $dates->push(Carbon::parse($d)->toDateString()));

        if (Schema::hasTable('employee_task_occurrences')) {
            $this->occurrenceQueryForEmployee($employeeId)
                ->where('status', EmployeeTaskStatus::Completed->value)
                ->whereNotNull('completed_at')
                ->pluck('completed_at')
                ->each(fn ($d) => $dates->push(Carbon::parse($d)->toDateString()));
        }

        $unique = $dates->unique()->sortDesc()->values();
        if ($unique->isEmpty()) {
            return 0;
        }

        $streak = 0;
        $cursor = now()->startOfDay();

        foreach ($unique as $dateStr) {
            $date = Carbon::parse($dateStr)->startOfDay();
            if ($date->equalTo($cursor) || $date->equalTo($cursor->copy()->subDay())) {
                $streak++;
                $cursor = $date->copy()->subDay();
            } else {
                break;
            }
        }

        return $streak;
    }

    private function periodChart(int $employeeId, string $period): array
    {
        $start = $period === 'week' ? now()->subDays(6)->startOfDay() : now()->subDays(29)->startOfDay();
        $labels = [];
        $completed = [];
        $assigned = [];

        $cursor = $start->copy();
        while ($cursor->lte(now())) {
            $key = $cursor->toDateString();
            $labels[] = $cursor->format($period === 'week' ? 'D' : 'd/m');

            $assigned[] = $this->legacyQueryForEmployee($employeeId)
                ->whereDate('start_time', $key)
                ->where('is_canceled', 0)
                ->count() + (
                    Schema::hasTable('employee_task_occurrences')
                        ? $this->occurrenceQueryForEmployee($employeeId)
                            ->whereDate('scheduled_date', $key)
                            ->where('is_canceled', 0)
                            ->count()
                        : 0
                );

            $completed[] = $this->legacyQueryForEmployee($employeeId)
                ->where('status', EmployeeTaskStatus::Completed->value)
                ->where(function ($q) use ($key) {
                    $q->whereDate('reviewed_at', $key)->orWhereDate('updated_at', $key);
                })
                ->count();

            $cursor->addDay();
        }

        return [
            'labels' => $labels,
            'assigned' => $assigned,
            'completed' => $completed,
        ];
    }

    private function legacyQueryForEmployee(int $employeeId)
    {
        return EmployeeTask::query()->where(function ($query) use ($employeeId) {
            $query->where('employee_id', $employeeId);
            if (Schema::hasTable('employee_task_assignees')) {
                $query->orWhereExists(function ($subquery) use ($employeeId) {
                    $subquery->selectRaw('1')
                        ->from('employee_task_assignees')
                        ->whereColumn('employee_task_assignees.employee_task_id', 'employee_tasks.id')
                        ->where('employee_task_assignees.employee_id', $employeeId);
                });
            }
        });
    }

    private function occurrenceQueryForEmployee(int $employeeId)
    {
        return EmployeeTaskOccurrence::query()->where(function ($query) use ($employeeId) {
            $query->where('employee_id', $employeeId);
            if (Schema::hasTable('employee_task_occurrence_assignees')) {
                $query->orWhereExists(function ($subquery) use ($employeeId) {
                    $subquery->selectRaw('1')
                        ->from('employee_task_occurrence_assignees')
                        ->whereColumn('employee_task_occurrence_assignees.occurrence_id', 'employee_task_occurrences.id')
                        ->where('employee_task_occurrence_assignees.employee_id', $employeeId);
                });
            } elseif (Schema::hasTable('employee_task_assignees')) {
                $query->orWhereIn('legacy_task_id', function ($subquery) use ($employeeId) {
                    $subquery->select('employee_task_id')
                        ->from('employee_task_assignees')
                        ->where('employee_id', $employeeId);
                });
            }
        });
    }

    private function leaderboard(int $limit): array
    {
        $employees = EmployeeDetail::query()
            ->with('user')
            ->get()
            ->values();

        $totals = app(EmployeePointsService::class)->getTotalNetPointsMany(
            $employees->pluck('id')->map(fn ($id) => (int) $id)->all()
        );

        return $employees
            ->sortByDesc(fn ($employee) => $totals[(int) $employee->id] ?? 0)
            ->take($limit)
            ->values()
            ->map(fn ($e, $i) => [
                'rank' => $i + 1,
                'employee_id' => $e->id,
                'employee_name' => $e->user->name ?? '',
                'points' => (int) ($totals[(int) $e->id] ?? 0),
            ])
            ->all();
    }

    private function badges(int $employeeId, int $completed, int $overdue): array
    {
        $badges = [];

        if ($completed >= 10) {
            $badges[] = ['key' => 'starter', 'label' => '10+ tasks completed'];
        }
        if ($completed >= 50) {
            $badges[] = ['key' => 'pro', 'label' => '50+ tasks completed'];
        }
        if ($overdue === 0 && $completed >= 5) {
            $badges[] = ['key' => 'on_time', 'label' => 'No overdue tasks'];
        }

        $streak = $this->calculateStreak($employeeId);
        if ($streak >= 7) {
            $badges[] = ['key' => 'streak_7', 'label' => '7-day streak'];
        }

        return $badges;
    }
}
