<?php

namespace App\Services\Goals;

use App\Models\EmployeeDetail;
use App\Models\Goal;
use App\Models\GoalDailySnapshot;
use App\Models\GoalEmployeeShare;
use App\Services\AdminNotificationService;
use App\Services\EmployeeNotificationService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class GoalNotificationService
{
    public const TYPE_GOAL_DAILY_SUMMARY = 'goal_daily_summary';

    public const TYPE_GOAL_NO_PROGRESS = 'goal_no_progress';

    public const TYPE_GOAL_SHARED = 'goal_shared';

    public const TIMEZONE = 'Asia/Hebron';

    public function __construct(
        private GoalCalculationService $calculator,
        private AdminNotificationService $adminNotifications,
        private EmployeeNotificationService $employeeNotifications
    ) {}

    public function decorateGoal(Goal $goal): array
    {
        $achievement = (float) ($goal->achievement_percentage ?? 0);
        $target = (float) ($goal->targeted_value ?? 0);
        $current = (float) ($goal->current_value ?? 0);

        $status = match (true) {
            $achievement >= 100 => ['key' => 'achieved', 'label' => 'محقق', 'color' => 'gold'],
            $achievement >= 80 => ['key' => 'excellent', 'label' => 'ممتاز', 'color' => 'green'],
            $achievement >= 50 => ['key' => 'in_progress', 'label' => 'قيد التقدم', 'color' => 'blue'],
            default => ['key' => 'behind', 'label' => 'متأخر', 'color' => 'red'],
        };

        return [
            'id' => (string) $goal->id,
            'name' => (string) $goal->name,
            'type' => (string) $goal->type,
            'form' => (string) ($goal->form ?? ''),
            'scope' => (string) ($goal->scope ?? ''),
            'current_value' => number_format($current, 2, '.', ''),
            'targeted_value' => number_format($target, 2, '.', ''),
            'achievement_percentage' => number_format($achievement, 2, '.', ''),
            'due_date' => optional($goal->due_date)->toDateString() ?? (string) $goal->due_date,
            'status_key' => $status['key'],
            'status_label' => $status['label'],
            'status_color' => $status['color'],
        ];
    }

    public function activeGoals(): Collection
    {
        $today = now(self::TIMEZONE)->toDateString();

        return Goal::query()
            ->where(function ($query) {
                $query->whereNull('is_canceled')->orWhere('is_canceled', 0);
            })
            ->where(function ($query) use ($today) {
                $query->whereNull('due_date')->orWhereDate('due_date', '>=', $today);
            })
            ->orderBy('due_date')
            ->get()
            ->map(fn (Goal $goal) => $this->calculator->recalculate($goal));
    }

    public function sendAdminDailySummary(bool $force = false): int
    {
        $now = now(self::TIMEZONE);
        $slot = $now->format('H:00');
        $dateKey = $now->toDateString();
        $cacheKey = "goals:admin_summary:{$dateKey}:{$slot}";

        if (! $force && Cache::has($cacheKey)) {
            return 0;
        }

        $goals = $this->activeGoals();
        $summary = $this->summary($goals);

        $this->adminNotifications->create(
            self::TYPE_GOAL_DAILY_SUMMARY,
            'ملخص الأهداف اليومي',
            $this->summaryBody($summary),
            [
                'date' => $dateKey,
                'slot' => $slot,
                'summary' => $summary,
                'goals' => $goals->take(10)->map(fn (Goal $goal) => $this->decorateGoal($goal))->values()->all(),
            ],
            null,
            'goals',
            null,
            true
        );

        $this->ensureTodaySnapshots($goals, $now);
        Cache::put($cacheKey, true, $now->copy()->endOfDay());

        return 1;
    }

    public function sendEmployeeDailySummaries(bool $force = false): int
    {
        $sent = 0;
        $now = now(self::TIMEZONE);
        $dateKey = $now->toDateString();
        $slot = $now->format('H:00');

        EmployeeDetail::query()
            ->whereHas('goalShares.goal', fn ($query) => $this->applyActiveGoalFilters($query, $dateKey))
            ->with(['goalShares.goal' => fn ($query) => $this->applyActiveGoalFilters($query, $dateKey)])
            ->chunkById(100, function ($employees) use (&$sent, $force, $dateKey, $slot, $now) {
                foreach ($employees as $employee) {
                    $cacheKey = "goals:employee_summary:{$employee->id}:{$dateKey}:{$slot}";
                    if (! $force && Cache::has($cacheKey)) {
                        continue;
                    }

                    $goals = $employee->goalShares
                        ->pluck('goal')
                        ->filter()
                        ->map(fn (Goal $goal) => $this->calculator->recalculate($goal))
                        ->values();

                    if ($goals->isEmpty()) {
                        continue;
                    }

                    $summary = $this->summary($goals);
                    $this->employeeNotifications->create(
                        $employee,
                        self::TYPE_GOAL_DAILY_SUMMARY,
                        'ملخص أهدافك اليوم',
                        $this->summaryBody($summary),
                        [
                            'date' => $dateKey,
                            'slot' => $slot,
                            'summary' => $summary,
                            'goals' => $goals->take(10)->map(fn (Goal $goal) => $this->decorateGoal($goal))->values()->all(),
                        ],
                        'goals',
                        null,
                        true
                    );
                    Cache::put($cacheKey, true, $now->copy()->endOfDay());
                    $sent++;
                }
            });

        return $sent;
    }

    public function sendNoProgressReminders(bool $force = false): int
    {
        $now = now(self::TIMEZONE);
        $dateKey = $now->toDateString();
        $goals = $this->activeGoals();
        $stalled = $goals->filter(function (Goal $goal) use ($dateKey) {
            $snapshot = GoalDailySnapshot::where('goal_id', $goal->id)
                ->whereDate('snapshot_date', $dateKey)
                ->first();

            return $snapshot && (float) $goal->current_value <= (float) $snapshot->current_value;
        })->values();

        if ($stalled->isEmpty()) {
            return 0;
        }

        $cacheKey = "goals:no_progress:admin:{$dateKey}";
        $sent = 0;
        if ($force || ! Cache::has($cacheKey)) {
            $names = $stalled->take(5)->pluck('name')->implode('، ');
            $this->adminNotifications->create(
                self::TYPE_GOAL_NO_PROGRESS,
                'تذكير أهداف بدون تقدم',
                "لم يتم تسجيل أي تقدم اليوم في {$stalled->count()} هدف: {$names}",
                [
                    'date' => $dateKey,
                    'count' => (string) $stalled->count(),
                    'goals' => $stalled->map(fn (Goal $goal) => $this->decorateGoal($goal))->values()->all(),
                ],
                null,
                'goals',
                null,
                true
            );
            Cache::put($cacheKey, true, $now->copy()->endOfDay());
            $sent++;
        }

        $shares = GoalEmployeeShare::whereIn('goal_id', $stalled->pluck('id'))
            ->with(['employee.user', 'goal'])
            ->get();

        foreach ($shares as $share) {
            if (! $share->employee || ! $share->goal) {
                continue;
            }
            $employeeCacheKey = "goals:no_progress:employee:{$share->employee_id}:{$share->goal_id}:{$dateKey}";
            if (! $force && Cache::has($employeeCacheKey)) {
                continue;
            }
            $this->employeeNotifications->create(
                $share->employee,
                self::TYPE_GOAL_NO_PROGRESS,
                'تذكير هدف بدون تقدم',
                "لم يتم تسجيل تقدم اليوم في هدف: {$share->goal->name}",
                [
                    'date' => $dateKey,
                    'goal' => $this->decorateGoal($share->goal),
                ],
                'goal',
                (int) $share->goal_id,
                true
            );
            Cache::put($employeeCacheKey, true, $now->copy()->endOfDay());
            $sent++;
        }

        return $sent;
    }

    public function notifyGoalShared(Goal $goal, EmployeeDetail $employee): void
    {
        $goal = $this->calculator->recalculate($goal);

        $this->employeeNotifications->create(
            $employee,
            self::TYPE_GOAL_SHARED,
            'تمت إضافتك لتحقيق هدف',
            "تمت مشاركتك في هدف: {$goal->name}",
            ['goal' => $this->decorateGoal($goal)],
            'goal',
            (int) $goal->id,
            true
        );
    }

    public function employeeGoals(int $employeeId): array
    {
        $today = now(self::TIMEZONE)->toDateString();

        return GoalEmployeeShare::where('employee_id', $employeeId)
            ->whereHas('goal', fn ($query) => $this->applyActiveGoalFilters($query, $today))
            ->with(['goal' => fn ($query) => $this->applyActiveGoalFilters($query, $today)])
            ->get()
            ->pluck('goal')
            ->filter()
            ->map(fn (Goal $goal) => $this->decorateGoal($this->calculator->recalculate($goal)))
            ->values()
            ->all();
    }

    private function ensureTodaySnapshots(Collection $goals, Carbon $now): void
    {
        foreach ($goals as $goal) {
            GoalDailySnapshot::updateOrCreate(
                [
                    'goal_id' => $goal->id,
                    'snapshot_date' => $now->toDateString(),
                ],
                [
                    'current_value' => (float) $goal->current_value,
                    'achievement_percentage' => (float) $goal->achievement_percentage,
                ]
            );
        }
    }

    private function applyActiveGoalFilters($query, string $today): void
    {
        $query
            ->where(function ($query) {
                $query->whereNull('is_canceled')->orWhere('is_canceled', 0);
            })
            ->where(function ($query) use ($today) {
                $query->whereNull('due_date')->orWhereDate('due_date', '>=', $today);
            });
    }

    private function summary(Collection $goals): array
    {
        $decorated = $goals->map(fn (Goal $goal) => $this->decorateGoal($goal));

        return [
            'total' => (string) $decorated->count(),
            'achieved' => (string) $decorated->where('status_key', 'achieved')->count(),
            'excellent' => (string) $decorated->where('status_key', 'excellent')->count(),
            'in_progress' => (string) $decorated->where('status_key', 'in_progress')->count(),
            'behind' => (string) $decorated->where('status_key', 'behind')->count(),
        ];
    }

    private function summaryBody(array $summary): string
    {
        return "الإجمالي: {$summary['total']}، محققة: {$summary['achieved']}، ممتازة: {$summary['excellent']}، قيد التقدم: {$summary['in_progress']}، متأخرة: {$summary['behind']}";
    }
}
