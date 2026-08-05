<?php

namespace App\Services\EmployeeTasks;

use App\Enums\EmployeeTaskStatus;
use App\Models\EmployeeDetail;
use App\Models\EmployeeTask;
use App\Models\EmployeeTaskOccurrence;
use App\Services\EmployeePointsService;
use Carbon\Carbon;

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
        $base = EmployeeTask::where('employee_id', $employeeId)->where('is_canceled', 0);

        return [
            'completed' => (clone $base)->where('status', EmployeeTaskStatus::Completed->value)->count(),
            'overdue' => (clone $base)->where('status', EmployeeTaskStatus::Overdue->value)->count(),
            'total' => (clone $base)->count(),
        ];
    }

    private function occurrenceStats(int $employeeId): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('employee_task_occurrences')) {
            return ['completed' => 0, 'overdue' => 0, 'total' => 0];
        }

        $base = EmployeeTaskOccurrence::where('employee_id', $employeeId)->where('is_canceled', 0);

        return [
            'completed' => (clone $base)->where('status', EmployeeTaskStatus::Completed->value)->count(),
            'overdue' => (clone $base)->where('status', EmployeeTaskStatus::Overdue->value)->count(),
            'total' => (clone $base)->count(),
        ];
    }

    private function calculateStreak(int $employeeId): int
    {
        $dates = collect();

        EmployeeTask::where('employee_id', $employeeId)
            ->where('status', EmployeeTaskStatus::Completed->value)
            ->whereNotNull('reviewed_at')
            ->pluck('reviewed_at')
            ->each(fn ($d) => $dates->push(Carbon::parse($d)->toDateString()));

        if (\Illuminate\Support\Facades\Schema::hasTable('employee_task_occurrences')) {
            EmployeeTaskOccurrence::where('employee_id', $employeeId)
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

            $assigned[] = EmployeeTask::where('employee_id', $employeeId)
                ->whereDate('start_time', $key)
                ->where('is_canceled', 0)
                ->count() + (
                    \Illuminate\Support\Facades\Schema::hasTable('employee_task_occurrences')
                        ? EmployeeTaskOccurrence::where('employee_id', $employeeId)
                            ->whereDate('scheduled_date', $key)
                            ->where('is_canceled', 0)
                            ->count()
                        : 0
                );

            $completed[] = EmployeeTask::where('employee_id', $employeeId)
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
