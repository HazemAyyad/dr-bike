<?php

namespace App\Services;

use App\Models\EmployeePointsLog;
use App\Models\EmployeeRewardRule;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

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

        return EmployeePointsLog::create([
            'employee_id' => $employeeId,
            'points' => $points,
            'operation_type' => $operationType,
            'category' => $category,
            'source' => $source,
            'reason' => isset($payload['reason']) ? (string) $payload['reason'] : null,
            'notes' => isset($payload['notes']) ? (string) $payload['notes'] : null,
            'points_date' => $pointsDate,
            'created_by' => $createdBy,
        ]);
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
     * }
     */
    public function getMonthlySummary(int $employeeId, int $year, int $month): array
    {
        $points = $this->getMonthlyPoints($employeeId, $year, $month);
        $rule = $this->matchRewardRule($points['net_points']);

        return [
            'earned_points' => (int) $points['earned_points'],
            'deducted_points' => (int) $points['deducted_points'],
            'net_points' => (int) $points['net_points'],
            'reward_amount' => $rule ? (float) $rule->reward_amount : 0.0,
            'matched_rule_id' => $rule?->id,
        ];
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

    /**
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
     * Return all known categories regardless of polarity.
     *
     * @return array<string>
     */
    public function allCategories(): array
    {
        return array_values(array_unique(array_merge(
            $this->positiveCategories(),
            $this->negativeCategories(),
        )));
    }
}
