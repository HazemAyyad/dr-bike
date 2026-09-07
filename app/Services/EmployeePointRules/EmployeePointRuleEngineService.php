<?php

namespace App\Services\EmployeePointRules;

use App\Enums\EmployeeTaskStatus;
use App\Models\EmployeeDetail;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeAttendanceScan;
use App\Models\EmployeePointRule;
use App\Models\EmployeePointRuleExecution;
use App\Models\EmployeePointRuleOverride;
use App\Models\EmployeePointsLog;
use App\Models\EmployeeTask;
use App\Models\EmployeeTaskOccurrence;
use App\Services\EmployeePointsService;
use App\Services\AttendanceSalaryService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EmployeePointRuleEngineService
{
    public function __construct(private readonly EmployeePointsService $pointsService)
    {
    }

    /**
     * @return array{rules:int, employees:int, awarded:int, deducted:int, zero:int, skipped:int}
     */
    public function run(?Carbon $anchor = null, ?int $ruleId = null, bool $force = false, bool $completedPeriodsOnly = false): array
    {
        $anchor ??= Carbon::now();

        if (! Schema::hasTable('employee_point_rules')) {
            return [
                'rules' => 0,
                'employees' => 0,
                'awarded' => 0,
                'deducted' => 0,
                'zero' => 0,
                'skipped' => 0,
            ];
        }

        /** @phpstan-ignore-next-line */
        $rules = EmployeePointRule::query()
            ->active()
            ->when($ruleId !== null, fn ($q) => $q->where('id', $ruleId))
            ->where(function ($q) use ($anchor) {
                $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $anchor->toDateString());
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $summary = [
            'rules' => 0,
            'employees' => 0,
            'awarded' => 0,
            'deducted' => 0,
            'zero' => 0,
            'skipped' => 0,
        ];

        foreach ($rules as $rule) {
            if ($completedPeriodsOnly && ! $this->isCompletedPeriodAnchor($rule->period_type, $anchor)) {
                continue;
            }

            $summary['rules']++;
            [$periodStart, $periodEnd] = $this->periodRange($rule->period_type, $anchor);
            $employees = $this->employeesForRule($rule);
            $summary['employees'] += $employees->count();

            $globalMatched = null;
            if ($rule->condition_type === EmployeePointRule::CONDITION_ALL_EMPLOYEES_COMPLETED_TASKS) {
                $globalMatched = $this->allEmployeesCompletedTasks($employees, $periodStart, $periodEnd);
            }

            foreach ($employees as $employee) {
                $result = $this->evaluateEmployee($rule, $employee, $periodStart, $periodEnd, $globalMatched);
                $execution = $this->applyResult($rule, $employee, $periodStart, $periodEnd, $result, $force);

                if ($execution->status === 'applied') {
                    $execution->points_log_id === null
                        ? $summary['zero']++
                        : ($execution->pointsLog?->operation_type === EmployeePointsLog::OPERATION_DEDUCT
                            ? $summary['deducted']++
                            : $summary['awarded']++);
                } else {
                    $summary['skipped']++;
                }
            }
        }

        return $summary;
    }

    /**
     * @return array{matched:bool, reason:string, details:array<string,mixed>}
     */
    private function evaluateEmployee(
        EmployeePointRule $rule,
        EmployeeDetail $employee,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?bool $globalMatched
    ): array {
        return match ($rule->condition_type) {
            EmployeePointRule::CONDITION_EMPLOYEE_COMPLETED_ALL_TASKS_BEFORE_TIME => $this->employeeCompletedAllTasksBeforeTime(
                $employee,
                $periodStart,
                $periodEnd,
                (array) ($rule->settings ?? [])
            ),
            EmployeePointRule::CONDITION_ALL_EMPLOYEES_COMPLETED_TASKS => [
                'matched' => (bool) $globalMatched,
                'reason' => $globalMatched ? 'all_employees_completed_tasks' : 'not_all_employees_completed_tasks',
                'details' => ['global_condition' => (bool) $globalMatched],
            ],
            EmployeePointRule::CONDITION_EMPLOYEE_HAS_INCOMPLETE_TASKS => $this->employeeHasIncompleteTasks(
                $employee,
                $periodStart,
                $periodEnd
            ),
            EmployeePointRule::CONDITION_EMPLOYEE_COMPLETED_ALL_TASKS => $this->employeeCompletedAllTasks(
                $employee,
                $periodStart,
                $periodEnd
            ),
            EmployeePointRule::CONDITION_EMPLOYEE_ATTENDED_ON_TIME => $this->employeeAttendedOnTime(
                $employee,
                $periodStart,
                $periodEnd,
                (array) ($rule->settings ?? [])
            ),
            EmployeePointRule::CONDITION_EMPLOYEE_PERFECT_ATTENDANCE_AND_TASKS => $this->employeePerfectAttendanceAndTasks(
                $employee,
                $periodStart,
                $periodEnd,
                (array) ($rule->settings ?? [])
            ),
            default => [
                'matched' => false,
                'reason' => 'unsupported_condition_type',
                'details' => ['condition_type' => $rule->condition_type],
            ],
        };
    }

    /**
     * @param  array{matched:bool, reason:string, details:array<string,mixed>}  $result
     */
    private function applyResult(
        EmployeePointRule $rule,
        EmployeeDetail $employee,
        Carbon $periodStart,
        Carbon $periodEnd,
        array $result,
        bool $force
    ): EmployeePointRuleExecution {
        /** @var EmployeePointRuleExecution|null $existing */
        $existing = EmployeePointRuleExecution::query()
            ->where('rule_id', $rule->id)
            ->where('employee_id', $employee->id)
            ->where('period_type', $rule->period_type)
            ->whereDate('period_start', $periodStart->toDateString())
            ->first();

        if ($existing && ! $force) {
            return $existing;
        }

        return DB::transaction(function () use ($rule, $employee, $periodStart, $periodEnd, $result, $existing, $force) {
            if ($existing && $force && $existing->points_log_id) {
                EmployeePointsLog::query()->where('id', $existing->points_log_id)->delete();
            }

            $payload = [
                'rule_id' => $rule->id,
                'employee_id' => $employee->id,
                'period_type' => $rule->period_type,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'status' => 'skipped',
                'reason' => $result['reason'],
                'details' => $result['details'],
                'points_log_id' => null,
            ];

            if ($result['matched']) {
                $override = $this->activeOverride($rule, $employee, $periodStart);
                if ($override?->is_excluded) {
                    $payload['reason'] = 'employee_excluded_by_override';
                    $payload['details']['override_id'] = (int) $override->id;
                } else {
                    $points = $override?->points ?? (int) $rule->default_points;
                    $operation = $override?->operation_type ?? $rule->operation_type;
                    $payload['status'] = 'applied';
                    $payload['details']['points'] = (int) $points;
                    $payload['details']['operation_type'] = $operation;
                    if ($override) {
                        $payload['details']['override_id'] = (int) $override->id;
                    }

                    if ((int) $points > 0) {
                        $logPayload = [
                            'points' => (int) $points,
                            'category' => 'rule_'.$rule->id,
                            'source' => EmployeePointsLog::SOURCE_RULE_ENGINE,
                            'reason' => $rule->name,
                            'notes' => $payload['reason'],
                            'points_date' => $periodEnd->toDateString(),
                        ];

                        $log = $operation === EmployeePointsLog::OPERATION_DEDUCT
                            ? $this->pointsService->deductPoints((int) $employee->id, $logPayload)
                            : $this->pointsService->addPoints((int) $employee->id, $logPayload);

                        $payload['points_log_id'] = (int) $log->id;
                    }
                }
            }

            $execution = EmployeePointRuleExecution::updateOrCreate(
                [
                    'rule_id' => $rule->id,
                    'employee_id' => $employee->id,
                    'period_type' => $rule->period_type,
                    'period_start' => $periodStart->toDateString(),
                ],
                $payload
            );

            return $execution->fresh(['pointsLog']);
        });
    }

    /**
     * @return Collection<int,EmployeeDetail>
     */
    private function employeesForRule(EmployeePointRule $rule): Collection
    {
        /** @phpstan-ignore-next-line */
        $query = EmployeeDetail::query()->with('user:id,name')->whereHas('user');

        if (! $rule->applies_to_all) {
            $ids = $rule->employees()->pluck('employee_details.id')->all();
            $query->whereIn('id', $ids);
        }

        return $query->orderBy('id')->get();
    }

    private function activeOverride(
        EmployeePointRule $rule,
        EmployeeDetail $employee,
        Carbon $periodStart
    ): ?EmployeePointRuleOverride {
        /** @phpstan-ignore-next-line */
        return EmployeePointRuleOverride::query()
            ->where('rule_id', $rule->id)
            ->where('employee_id', $employee->id)
            ->where(function ($q) use ($periodStart) {
                $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $periodStart->toDateString());
            })
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array{0:Carbon,1:Carbon}
     */
    private function periodRange(string $periodType, Carbon $anchor): array
    {
        return match ($periodType) {
            EmployeePointRule::PERIOD_WEEKLY => [$anchor->copy()->startOfWeek(), $anchor->copy()->endOfWeek()],
            EmployeePointRule::PERIOD_MONTHLY => [$anchor->copy()->startOfMonth(), $anchor->copy()->endOfMonth()],
            default => [$anchor->copy()->startOfDay(), $anchor->copy()->endOfDay()],
        };
    }

    private function isCompletedPeriodAnchor(string $periodType, Carbon $anchor): bool
    {
        return match ($periodType) {
            EmployeePointRule::PERIOD_WEEKLY => $anchor->isSunday(),
            EmployeePointRule::PERIOD_MONTHLY => $anchor->isLastOfMonth(),
            default => true,
        };
    }

    /**
     * @return array{matched:bool, reason:string, details:array<string,mixed>}
     */
    private function employeeCompletedAllTasksBeforeTime(
        EmployeeDetail $employee,
        Carbon $periodStart,
        Carbon $periodEnd,
        array $settings
    ): array {
        $stats = $this->taskStats($employee, $periodStart, $periodEnd);
        $cutoff = $this->cutoffAt($periodEnd, (string) ($settings['cutoff_time'] ?? '02:00'));

        if ($stats['total'] < 1) {
            return ['matched' => false, 'reason' => 'no_tasks_in_period', 'details' => $stats];
        }
        if ($stats['incomplete'] > 0) {
            return ['matched' => false, 'reason' => 'employee_has_incomplete_tasks', 'details' => $stats];
        }
        if ($stats['latest_completed_at'] === null || $stats['latest_completed_at']->greaterThan($cutoff)) {
            return [
                'matched' => false,
                'reason' => 'completed_after_cutoff',
                'details' => array_merge($stats, ['cutoff_at' => $cutoff->toDateTimeString()]),
            ];
        }

        return [
            'matched' => true,
            'reason' => 'completed_all_tasks_before_cutoff',
            'details' => array_merge($stats, ['cutoff_at' => $cutoff->toDateTimeString()]),
        ];
    }

    /**
     * @return array{matched:bool, reason:string, details:array<string,mixed>}
     */
    private function employeeHasIncompleteTasks(EmployeeDetail $employee, Carbon $periodStart, Carbon $periodEnd): array
    {
        $stats = $this->taskStats($employee, $periodStart, $periodEnd);
        if ($stats['total'] < 1) {
            return ['matched' => false, 'reason' => 'no_tasks_in_period', 'details' => $stats];
        }

        return [
            'matched' => $stats['incomplete'] > 0,
            'reason' => $stats['incomplete'] > 0 ? 'employee_has_incomplete_tasks' : 'employee_completed_all_tasks',
            'details' => $stats,
        ];
    }

    private function employeeCompletedAllTasks(EmployeeDetail $employee, Carbon $periodStart, Carbon $periodEnd): array
    {
        $stats = $this->taskStats($employee, $periodStart, $periodEnd);
        if ($stats['total'] < 1) {
            return ['matched' => false, 'reason' => 'no_tasks_in_period', 'details' => $stats];
        }

        return [
            'matched' => $stats['incomplete'] === 0,
            'reason' => $stats['incomplete'] === 0 ? 'employee_completed_all_tasks' : 'employee_has_incomplete_tasks',
            'details' => $stats,
        ];
    }

    private function employeeAttendedOnTime(
        EmployeeDetail $employee,
        Carbon $periodStart,
        Carbon $periodEnd,
        array $settings
    ): array {
        $graceMinutes = max(0, (int) ($settings['grace_minutes'] ?? 0));
        $daysOff = app(AttendanceSalaryService::class)->effectiveWeeklyDaysOff($employee);
        $workDays = 0;
        $onTimeDays = 0;
        $lateDays = 0;
        $missingDays = 0;
        $day = $periodStart->copy()->startOfDay();
        $lastDay = $periodEnd->copy()->startOfDay();

        while ($day->lte($lastDay)) {
            if (! in_array(strtolower($day->format('l')), $daysOff, true)) {
                $workDays++;
                $arrival = $this->firstArrivalAt($employee, $day);
                $scheduledStart = $this->scheduledStartAt($employee, $day);

                if ($arrival === null || $scheduledStart === null) {
                    $missingDays++;
                } elseif ($arrival->lte($scheduledStart->copy()->addMinutes($graceMinutes))) {
                    $onTimeDays++;
                } else {
                    $lateDays++;
                }
            }
            $day->addDay();
        }

        $details = compact('workDays', 'onTimeDays', 'lateDays', 'missingDays', 'graceMinutes');
        $matched = $workDays > 0 && $onTimeDays === $workDays;

        return [
            'matched' => $matched,
            'reason' => $matched ? 'employee_attended_on_time' : ($lateDays > 0 ? 'employee_arrived_late' : 'employee_missing_attendance'),
            'details' => $details,
        ];
    }

    private function employeePerfectAttendanceAndTasks(
        EmployeeDetail $employee,
        Carbon $periodStart,
        Carbon $periodEnd,
        array $settings
    ): array {
        $attendance = $this->employeeAttendedOnTime($employee, $periodStart, $periodEnd, $settings);
        $tasks = $this->taskStats($employee, $periodStart, $periodEnd);
        $matched = $attendance['matched'] && $tasks['incomplete'] === 0;

        return [
            'matched' => $matched,
            'reason' => $matched
                ? 'employee_perfect_attendance_and_tasks'
                : (! $attendance['matched'] ? $attendance['reason'] : 'employee_has_incomplete_tasks'),
            'details' => [
                'attendance' => $attendance['details'],
                'tasks' => $tasks,
            ],
        ];
    }

    private function firstArrivalAt(EmployeeDetail $employee, Carbon $day): ?Carbon
    {
        $scan = EmployeeAttendanceScan::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $day->toDateString())
            ->where('direction', 'in')
            ->orderBy('scanned_at')
            ->value('scanned_at');

        if ($scan !== null) {
            return Carbon::parse($scan);
        }

        $arrival = EmployeeAttendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('date', $day->toDateString())
            ->value('arrived_at');

        return $arrival !== null
            ? Carbon::parse($day->toDateString().' '.$arrival, config('app.timezone'))
            : null;
    }

    private function scheduledStartAt(EmployeeDetail $employee, Carbon $day): ?Carbon
    {
        $start = trim((string) ($employee->start_work_time ?? ''));

        return $start === ''
            ? null
            : Carbon::parse($day->toDateString().' '.$start, config('app.timezone'));
    }

    /**
     * @param  Collection<int,EmployeeDetail>  $employees
     */
    private function allEmployeesCompletedTasks(Collection $employees, Carbon $periodStart, Carbon $periodEnd): bool
    {
        if ($employees->isEmpty()) {
            return false;
        }

        foreach ($employees as $employee) {
            $stats = $this->taskStats($employee, $periodStart, $periodEnd);
            if ($stats['total'] < 1 || $stats['incomplete'] > 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{total:int, completed:int, incomplete:int, latest_completed_at:?Carbon}
     */
    private function taskStats(EmployeeDetail $employee, Carbon $periodStart, Carbon $periodEnd): array
    {
        $completedStatuses = [EmployeeTaskStatus::Completed->value];
        $total = 0;
        $completed = 0;
        $latestCompletedAt = null;

        /** @phpstan-ignore-next-line */
        $occurrences = EmployeeTaskOccurrence::query()
            ->where('employee_id', $employee->id)
            ->where('is_canceled', false)
            ->whereBetween('scheduled_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->get();

        foreach ($occurrences as $task) {
            $total++;
            if (in_array($task->status, $completedStatuses, true)) {
                $completed++;
                $latestCompletedAt = $this->maxCarbon($latestCompletedAt, $task->completed_at ?? $task->reviewed_at ?? $task->updated_at);
            }
        }

        /** @phpstan-ignore-next-line */
        $legacyTasks = EmployeeTask::query()
            ->where('employee_id', $employee->id)
            ->where(function ($q) {
                $q->whereNull('is_canceled')->orWhere('is_canceled', false);
            })
            ->whereNull('occurrence_id')
            ->whereBetween('start_time', [$periodStart->toDateTimeString(), $periodEnd->toDateTimeString()])
            ->get();

        foreach ($legacyTasks as $task) {
            $total++;
            if (in_array($task->status, $completedStatuses, true)) {
                $completed++;
                $latestCompletedAt = $this->maxCarbon(
                    $latestCompletedAt,
                    $task->reviewed_at ?? $task->submitted_at ?? $task->updated_at
                );
            }
        }

        return [
            'total' => $total,
            'completed' => $completed,
            'incomplete' => max(0, $total - $completed),
            'latest_completed_at' => $latestCompletedAt,
        ];
    }

    private function cutoffAt(Carbon $periodEnd, string $time): Carbon
    {
        if (! preg_match('/^\d{2}:\d{2}$/', $time)) {
            $time = '02:00';
        }

        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $periodEnd->copy()->startOfDay()->addDay()->setTime($hour, $minute);
    }

    private function maxCarbon(?Carbon $current, mixed $candidate): ?Carbon
    {
        if ($candidate === null) {
            return $current;
        }

        $carbon = $candidate instanceof Carbon ? $candidate : Carbon::parse($candidate);

        return $current === null || $carbon->greaterThan($current) ? $carbon : $current;
    }
}
