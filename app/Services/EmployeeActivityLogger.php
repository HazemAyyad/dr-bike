<?php

namespace App\Services;

use App\Models\EmployeeActivityLog;
use App\Models\EmployeeDetail;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class EmployeeActivityLogger
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function log(
        ?int $employeeId,
        ?User $actor,
        string $module,
        string $action,
        string $title,
        ?string $description = null,
        ?Model $subject = null,
        ?float $amount = null,
        array $metadata = [],
        ?string $subjectType = null,
        ?int $subjectId = null
    ): ?EmployeeActivityLog {
        try {
            $employee = $this->resolveEmployee($employeeId, $actor);
            if (! $employee) {
                return null;
            }

            return EmployeeActivityLog::create([
                'employee_id' => $employee->id,
                'actor_user_id' => $actor?->id,
                'module' => $module,
                'action' => $action,
                'title' => $title,
                'description' => $description,
                'subject_type' => $subjectType ?? $this->subjectType($subject),
                'subject_id' => $subjectId ?? ($subject?->getKey() ? (int) $subject->getKey() : null),
                'amount' => $amount,
                'metadata' => $metadata === [] ? null : $metadata,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Employee activity log failed', [
                'employee_id' => $employeeId,
                'module' => $module,
                'action' => $action,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function logForUserId(
        ?int $userId,
        string $module,
        string $action,
        string $title,
        ?string $description = null,
        ?Model $subject = null,
        ?float $amount = null,
        array $metadata = [],
        ?string $subjectType = null,
        ?int $subjectId = null
    ): ?EmployeeActivityLog {
        $actor = $userId ? User::query()->with('employee')->find($userId) : null;

        return $this->log(
            null,
            $actor,
            $module,
            $action,
            $title,
            $description,
            $subject,
            $amount,
            $metadata,
            $subjectType,
            $subjectId
        );
    }

    private function resolveEmployee(?int $employeeId, ?User $actor): ?EmployeeDetail
    {
        if ($employeeId) {
            return EmployeeDetail::query()->find($employeeId);
        }

        if ($actor?->relationLoaded('employee')) {
            return $actor->employee;
        }

        return $actor?->employee()->first();
    }

    private function subjectType(?Model $subject): ?string
    {
        if (! $subject) {
            return null;
        }

        return match ($subject::class) {
            \App\Models\InstantSale::class => 'instant_sale',
            \App\Models\Store\StoreSalesOrder::class => 'sales_order',
            \App\Models\Debt::class => 'debt',
            \App\Models\EmployeeAttendance::class => 'employee_attendance',
            \App\Models\EmployeeOrder::class => 'employee_order',
            \App\Models\Maintenance::class => 'maintenance',
            \App\Models\EmployeeTask::class => 'employee_task',
            \App\Models\EmployeeTaskOccurrence::class => 'employee_task_occurrence',
            \App\Models\EmployeeSubTask::class => 'employee_sub_task',
            \App\Models\SpecialTask::class => 'special_task',
            default => class_basename($subject),
        };
    }
}
