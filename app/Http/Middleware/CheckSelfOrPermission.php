<?php

namespace App\Http\Middleware;

use App\Models\EmployeeTask;
use App\Models\EmployeeTaskOccurrence;
use App\Services\EmployeeTasks\EmployeeTaskAssigneeService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckSelfOrPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, $modelName, $filed = 'employee_task_id', ...$permissions): Response
    {
        $user = $request->user();

        $models = [
            'employeeTask' => EmployeeTask::class,
        ];

        if ($user && $user->type === 'admin') {
            return $next($request);
        }

        if ($user && $user->type === 'employee' && $user->employee) {
            $employeeId = (int) $user->employee->id;

            if ($modelName === 'employeeTask' && $this->employeeCanAccessTaskRequest($request, $employeeId)) {
                return $next($request);
            }

            if (isset($models[$modelName])) {
                $model = $models[$modelName];
                $instance = $model::find($request->input($filed));
                if ($instance && $employeeId === (int) $instance->employee_id) {
                    return $next($request);
                }
            }

            foreach ($permissions as $permission) {
                $hasPermission = $user->employee->permissions()
                    ->whereHas('permission', function ($q) use ($permission) {
                        $q->where('name_en', $permission);
                    })
                    ->exists();

                if ($hasPermission) {
                    return $next($request);
                }
            }
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Unauthorized. Requires self or one of: '.implode(', ', $permissions),
        ], 200);
    }

    private function employeeCanAccessTaskRequest(Request $request, int $employeeId): bool
    {
        $assigneeService = app(EmployeeTaskAssigneeService::class);

        if ($request->filled('employee_task_id')) {
            $task = EmployeeTask::find($request->input('employee_task_id'));
            if ($task && $this->employeeCanAccessTask($task, $employeeId, $assigneeService)) {
                return true;
            }
        }

        if ($request->filled('occurrence_id')) {
            $occurrence = EmployeeTaskOccurrence::find($request->input('occurrence_id'));
            if (! $occurrence) {
                return false;
            }

            if ((int) $occurrence->employee_id === $employeeId) {
                return true;
            }

            if ($occurrence->legacy_task_id) {
                $legacy = EmployeeTask::find($occurrence->legacy_task_id);
                if ($legacy && $this->employeeCanAccessTask($legacy, $employeeId, $assigneeService)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function employeeCanAccessTask(
        EmployeeTask $task,
        int $employeeId,
        EmployeeTaskAssigneeService $assigneeService
    ): bool {
        if ((int) $task->employee_id === $employeeId) {
            return true;
        }

        return $assigneeService->isAssignee($task, $employeeId);
    }
}

