<?php

namespace App\Services;

use App\Models\EmployeePointCategory;
use App\Models\EmployeeDetail;
use App\Models\EmployeePointsLog;
use App\Models\EmployeeRewardRule;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class EmployeePointsService
{
    /**
     * Append a positive points entry for an employee.
     *
     * @param  array<string, mixed>  $payload
     */
    public function addPoints(int $employeeId, array $payload): EmployeePointsLog
    {
        return $this->createLog($employeeId, EmployeePointsLog::OPERATION_ADD, $payload);
    }

    /**
     * Append a negative points entry for an employee.
     *
     * @param  array<string, mixed>  $payload
     */
    public function deductPoints(int $employeeId, array $payload): EmployeePointsLog
    {
        return $this->createLog($employeeId, EmployeePointsLog::OPERATION_DEDUCT, $payload);
    }

    /**
     * Unified mutation when a category is provided. Operation type and default
     * points are taken from the category, with optional points override.
     *
     * @param  array<string, mixed>  $payload
     */
    public function applyCategoryMutation(int $employeeId, EmployeePointCategory $category, array $payload): EmployeePointsLog
    {
        $payload['category_id'] = $category->id;
        $payload['category'] = $category->code;

        if (! isset($payload['points']) || (int) $payload['points'] < 1) {
            $payload['points'] = (int) $category->default_points;
        }

        return $this->createLog($employeeId, $category->operation_type, $payload);
    }

    /**
     * Persist a points log entry.
     *
     * @param  array<string, mixed>  $payload
     */
    private function createLog(int $employeeId, string $operationType, array $payload): EmployeePointsLog
    {
        $points = (int) ($payload['points'] ?? 0);
        if ($points < 1) {
            $points = 1;
        }

        $category = isset($payload['category']) ? (string) $payload['category'] : 'manual';
        $categoryId = isset($payload['category_id']) ? (int) $payload['category_id'] : null;
        $source = isset($payload['source']) ? (string) $payload['source'] : EmployeePointsLog::SOURCE_MANUAL;
        if (! in_array($source, config('employee_points.sources', []), true)) {
            $source = EmployeePointsLog::SOURCE_MANUAL;
        }

        $pointsDate = isset($payload['points_date']) && $payload['points_date'] !== null
            ? Carbon::parse((string) $payload['points_date'])->toDateString()
            : Carbon::now()->toDateString();

        $createdBy = $payload['created_by'] ?? null;
        if ($createdBy === null && Auth::check()) {
            $createdBy = (int) Auth::id();
        }

        $log = EmployeePointsLog::create([
            'employee_id' => $employeeId,
            'points' => $points,
            'operation_type' => $operationType,
            'category' => $category,
            'category_id' => $categoryId,
            'source' => $source,
            'reason' => isset($payload['reason']) ? (string) $payload['reason'] : null,
            'notes' => isset($payload['notes']) ? (string) $payload['notes'] : null,
            'points_date' => $pointsDate,
            'created_by' => $createdBy,
        ]);

        app(EmployeeActivityLogger::class)->log(
            $employeeId,
            $createdBy ? \App\Models\User::query()->find((int) $createdBy) : null,
            'employee_points',
            $operationType === EmployeePointsLog::OPERATION_ADD ? 'points_added' : 'points_deducted',
            $operationType === EmployeePointsLog::OPERATION_ADD ? 'إضافة نقاط' : 'خصم نقاط',
            ($operationType === EmployeePointsLog::OPERATION_ADD ? 'تمت إضافة ' : 'تم خصم ').$points.' نقطة',
            $log,
            null,
            [
                'points' => $points,
                'category' => $category,
                'category_id' => $categoryId,
                'source' => $source,
                'reason' => $log->reason,
                'notes' => $log->notes,
                'points_date' => $pointsDate,
            ],
            'employee_points_log',
            (int) $log->id
        );

        $this->notifyPointsMutation($log);
        $this->notifyRewardEarnedIfNeeded($log);

        return $log;
    }

    /**
     * Aggregate add/deduct totals for the requested calendar month.
     *
     * @return array{earned_points:int, deducted_points:int, net_points:int}
     */
    public function getMonthlyPoints(int $employeeId, int $year, int $month): array
    {
        /** @phpstan-ignore-next-line */
        $rows = EmployeePointsLog::query()
            ->forEmployee($employeeId)
            ->inMonth($year, $month)
            ->selectRaw('operation_type, COALESCE(SUM(points), 0) as total_points')
            ->groupBy('operation_type')
            ->pluck('total_points', 'operation_type');

        $earned = (int) ($rows[EmployeePointsLog::OPERATION_ADD] ?? 0);
        $deducted = (int) ($rows[EmployeePointsLog::OPERATION_DEDUCT] ?? 0);
        $net = $earned - $deducted;

        return [
            'earned_points' => max(0, $earned),
            'deducted_points' => max(0, $deducted),
            'net_points' => $net,
        ];
    }

    /**
     * Resolve the monetary reward associated with a net points value using
     * the active reward rules table. When no rule matches the result is 0.
     */
    public function getMonthlyReward(int $netPoints): float
    {
        $rule = $this->matchRewardRule($netPoints);

        if ($rule === null) {
            return 0.0;
        }

        return (float) $rule->reward_amount;
    }

    /**
     * Build a self-contained monthly summary used by reports and the API.
     *
     * @return array{
     *     earned_points:int,
     *     deducted_points:int,
     *     net_points:int,
     *     reward_amount:float,
     *     matched_rule_id:?int,
     *     reward_rule_id:?int,
     *     reward_status_label:?string,
     *     reward_status_color:?string,
     * }
     */
    public function getMonthlySummary(int $employeeId, int $year, int $month): array
    {
        $points = $this->getMonthlyPoints($employeeId, $year, $month);
        $rule = $this->matchRewardRule($points['net_points']);

        $statusLabel = $rule?->status_label;
        $statusColor = $rule?->status_color;

        // Fallback heuristic when rule does not provide status info.
        if ($statusColor === null) {
            $statusColor = $this->fallbackStatusColor($points['net_points'], $rule);
        }
        if ($statusLabel === null) {
            $statusLabel = $this->fallbackStatusLabel($points['net_points'], $rule);
        }

        return [
            'earned_points' => (int) $points['earned_points'],
            'deducted_points' => (int) $points['deducted_points'],
            'net_points' => (int) $points['net_points'],
            'reward_amount' => $rule ? (float) $rule->reward_amount : 0.0,
            'matched_rule_id' => $rule?->id,
            'reward_rule_id' => $rule?->id,
            'reward_status_label' => $statusLabel,
            'reward_status_color' => $statusColor,
        ];
    }

    /**
     * Bulk summary for many employees at once to avoid N+1 queries.
     *
     * @param  array<int>  $employeeIds
     * @return array<int, array<string, mixed>>
     */
    public function getMonthlySummaryMany(array $employeeIds, int $year, int $month): array
    {
        if (empty($employeeIds)) {
            return [];
        }

        $start = Carbon::create($year, $month, 1)->startOfMonth()->toDateString();
        $end = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();

        /** @phpstan-ignore-next-line */
        $rows = EmployeePointsLog::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('points_date', [$start, $end])
            ->selectRaw('employee_id, operation_type, COALESCE(SUM(points), 0) as total_points')
            ->groupBy('employee_id', 'operation_type')
            ->get();

        $byEmployee = [];
        foreach ($rows as $row) {
            $eid = (int) $row->employee_id;
            $byEmployee[$eid] ??= ['earned_points' => 0, 'deducted_points' => 0];
            if ($row->operation_type === EmployeePointsLog::OPERATION_ADD) {
                $byEmployee[$eid]['earned_points'] += (int) $row->total_points;
            } elseif ($row->operation_type === EmployeePointsLog::OPERATION_DEDUCT) {
                $byEmployee[$eid]['deducted_points'] += (int) $row->total_points;
            }
        }

        $result = [];
        foreach ($employeeIds as $eid) {
            $eid = (int) $eid;
            $earned = (int) ($byEmployee[$eid]['earned_points'] ?? 0);
            $deducted = (int) ($byEmployee[$eid]['deducted_points'] ?? 0);
            $net = $earned - $deducted;
            $rule = $this->matchRewardRule($net);

            $statusLabel = $rule?->status_label ?? $this->fallbackStatusLabel($net, $rule);
            $statusColor = $rule?->status_color ?? $this->fallbackStatusColor($net, $rule);

            $result[$eid] = [
                'earned_points' => max(0, $earned),
                'deducted_points' => max(0, $deducted),
                'net_points' => $net,
                'reward_amount' => $rule ? (float) $rule->reward_amount : 0.0,
                'reward_rule_id' => $rule?->id,
                'reward_status_label' => $statusLabel,
                'reward_status_color' => $statusColor,
            ];
        }

        return $result;
    }

    /**
     * Lifetime net points from the logs table. This replaces the legacy
     * employee_details.points counter for performance and compatibility payloads.
     */
    public function getTotalNetPoints(int $employeeId): int
    {
        $rows = EmployeePointsLog::query()
            ->forEmployee($employeeId)
            ->selectRaw('operation_type, COALESCE(SUM(points), 0) as total_points')
            ->groupBy('operation_type')
            ->pluck('total_points', 'operation_type');

        return (int) ($rows[EmployeePointsLog::OPERATION_ADD] ?? 0)
            - (int) ($rows[EmployeePointsLog::OPERATION_DEDUCT] ?? 0);
    }

    /**
     * @param  array<int>  $employeeIds
     * @return array<int,int>
     */
    public function getTotalNetPointsMany(array $employeeIds): array
    {
        if (empty($employeeIds)) {
            return [];
        }

        $rows = EmployeePointsLog::query()
            ->whereIn('employee_id', $employeeIds)
            ->selectRaw('employee_id, operation_type, COALESCE(SUM(points), 0) as total_points')
            ->groupBy('employee_id', 'operation_type')
            ->get();

        $result = [];
        foreach ($employeeIds as $employeeId) {
            $result[(int) $employeeId] = 0;
        }

        foreach ($rows as $row) {
            $employeeId = (int) $row->employee_id;
            $points = (int) $row->total_points;
            if ($row->operation_type === EmployeePointsLog::OPERATION_ADD) {
                $result[$employeeId] = ($result[$employeeId] ?? 0) + $points;
            } elseif ($row->operation_type === EmployeePointsLog::OPERATION_DEDUCT) {
                $result[$employeeId] = ($result[$employeeId] ?? 0) - $points;
            }
        }

        return $result;
    }

    /**
     * Pick the most specific active reward rule for a given net points value.
     * Rules with a non-null max_points are preferred; ties break on min_points.
     */
    public function matchRewardRule(int $netPoints): ?EmployeeRewardRule
    {
        /** @phpstan-ignore-next-line */
        return EmployeeRewardRule::query()
            ->active()
            ->where('min_points', '<=', $netPoints)
            ->where(function ($q) use ($netPoints) {
                $q->whereNull('max_points')->orWhere('max_points', '>=', $netPoints);
            })
            ->orderByRaw('CASE WHEN max_points IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('min_points')
            ->first();
    }

    private function notifyPointsMutation(EmployeePointsLog $log): void
    {
        try {
            $employee = EmployeeDetail::query()
                ->with('user:id,name,fcm_token')
                ->find((int) $log->employee_id);

            if (! $employee) {
                return;
            }

            $isDeduct = $log->operation_type === EmployeePointsLog::OPERATION_DEDUCT;
            $employeeName = (string) ($employee->user->name ?? "موظف #{$employee->id}");
            $points = (int) $log->points;
            $operationLabel = $isDeduct ? 'خصم' : 'إضافة';
            $employeeTitle = $isDeduct ? 'تم خصم نقاط' : 'تمت إضافة نقاط';
            $employeeBody = $isDeduct
                ? "تم خصم {$points} نقطة من رصيدك."
                : "تمت إضافة {$points} نقطة إلى رصيدك.";
            if ($log->reason) {
                $employeeBody .= " السبب: {$log->reason}";
            }

            $data = [
                'points_log_id' => (string) $log->id,
                'employee_id' => (string) $employee->id,
                'employee_name' => $employeeName,
                'operation_type' => (string) $log->operation_type,
                'points' => (string) $points,
                'category' => (string) ($log->category ?? ''),
                'category_id' => $log->category_id !== null ? (string) $log->category_id : '',
                'source' => (string) ($log->source ?? ''),
                'reason' => (string) ($log->reason ?? ''),
                'points_date' => optional($log->points_date)->toDateString() ?? '',
            ];

            app(EmployeeNotificationService::class)->create(
                $employee,
                EmployeeNotificationService::TYPE_EMPLOYEE_POINTS_CHANGED,
                $employeeTitle,
                $employeeBody,
                $data,
                'employee_points_log',
                (int) $log->id,
                true
            );

            app(AdminNotificationService::class)->create(
                AdminNotificationService::TYPE_EMPLOYEE_POINTS_CHANGED,
                'تحديث نقاط موظف',
                "تم {$operationLabel} {$points} نقطة للموظف {$employeeName}.",
                $data,
                (int) $employee->id,
                'employee_points_log',
                (int) $log->id,
                true
            );
        } catch (\Throwable $e) {
            Log::warning('Employee points notification failed', [
                'points_log_id' => $log->id,
                'employee_id' => $log->employee_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function notifyRewardEarnedIfNeeded(EmployeePointsLog $log): void
    {
        try {
            $pointsDate = Carbon::parse($log->points_date);
            $year = (int) $pointsDate->year;
            $month = (int) $pointsDate->month;
            $summary = $this->getMonthlySummary((int) $log->employee_id, $year, $month);
            $ruleId = $summary['reward_rule_id'] ?? null;
            $rewardAmount = (float) ($summary['reward_amount'] ?? 0);

            if ($ruleId === null || $rewardAmount <= 0) {
                return;
            }

            $employee = EmployeeDetail::query()
                ->with('user:id,name,fcm_token')
                ->find((int) $log->employee_id);

            if (! $employee) {
                return;
            }

            if ($this->rewardNotificationAlreadySent((int) $employee->id, $year, $month, (int) $ruleId)) {
                return;
            }

            $employeeName = (string) ($employee->user->name ?? "موظف #{$employee->id}");
            $amount = number_format($rewardAmount, 2, '.', '');
            $netPoints = (int) ($summary['net_points'] ?? 0);
            $monthLabel = $pointsDate->locale('ar')->translatedFormat('F Y');
            $data = [
                'employee_id' => (string) $employee->id,
                'employee_name' => $employeeName,
                'year' => (string) $year,
                'month' => (string) $month,
                'month_label' => $monthLabel,
                'net_points' => (string) $netPoints,
                'reward_amount' => $amount,
                'reward_rule_id' => (string) $ruleId,
                'points_log_id' => (string) $log->id,
            ];

            app(EmployeeNotificationService::class)->create(
                $employee,
                EmployeeNotificationService::TYPE_EMPLOYEE_REWARD_EARNED,
                'استحققت مكافأة',
                "استحققت مكافأة بقيمة {$amount} لهذا الشهر بعد وصولك إلى {$netPoints} نقطة.",
                $data,
                'employee_reward_rule',
                (int) $ruleId,
                true
            );

            app(AdminNotificationService::class)->create(
                AdminNotificationService::TYPE_EMPLOYEE_REWARD_EARNED,
                'موظف استحق مكافأة',
                "الموظف {$employeeName} استحق مكافأة بقيمة {$amount} بعد وصوله إلى {$netPoints} نقطة.",
                $data,
                (int) $employee->id,
                'employee_reward_rule',
                (int) $ruleId,
                true
            );
        } catch (\Throwable $e) {
            Log::warning('Employee reward notification failed', [
                'points_log_id' => $log->id,
                'employee_id' => $log->employee_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function rewardNotificationAlreadySent(int $employeeId, int $year, int $month, int $ruleId): bool
    {
        $matchesReward = function ($query) use ($year, $month, $ruleId) {
            $query->where('type', EmployeeNotificationService::TYPE_EMPLOYEE_REWARD_EARNED)
                ->where('related_type', 'employee_reward_rule')
                ->where('related_id', $ruleId)
                ->where('data->year', (string) $year)
                ->where('data->month', (string) $month);
        };

        return \App\Models\EmployeeNotification::query()
            ->where('employee_id', $employeeId)
            ->where($matchesReward)
            ->exists();
    }

    /**
     * Heuristic colour when reward rules do not declare a colour.
     */
    private function fallbackStatusColor(int $netPoints, ?EmployeeRewardRule $rule): string
    {
        if ($netPoints < 0) {
            return '#DC2626'; // red
        }
        if ($netPoints === 0) {
            return '#9CA3AF'; // grey
        }
        if ($rule === null) {
            return '#F59E0B'; // orange
        }
        if ($rule->max_points === null) {
            return '#16A34A'; // green - open ended highest tier
        }

        return '#2563EB'; // blue
    }

    private function fallbackStatusLabel(int $netPoints, ?EmployeeRewardRule $rule): string
    {
        if ($netPoints < 0) {
            return __('messages.reward_status_negative');
        }
        if ($netPoints === 0) {
            return __('messages.reward_status_none');
        }
        if ($rule === null) {
            return __('messages.reward_status_no_match');
        }
        if ($rule->max_points === null) {
            return __('messages.reward_status_top');
        }

        return __('messages.reward_status_matched');
    }

    /**
     * Legacy: configured category codes used before the categories table.
     *
     * @return array<string>
     */
    public function positiveCategories(): array
    {
        return (array) config('employee_points.positive_categories', []);
    }

    /**
     * @return array<string>
     */
    public function negativeCategories(): array
    {
        return (array) config('employee_points.negative_categories', []);
    }

    /**
     * Return all known category codes regardless of polarity, merging
     * both the configured defaults and any custom ones from the database.
     *
     * @return array<string>
     */
    public function allCategories(): array
    {
        $codes = array_merge($this->positiveCategories(), $this->negativeCategories());

        /** @phpstan-ignore-next-line */
        $dbCodes = EmployeePointCategory::query()
            ->active()
            ->pluck('code')
            ->all();

        return array_values(array_unique(array_merge($codes, $dbCodes)));
    }
}
