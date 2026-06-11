<?php

namespace App\Http\Middleware;

use App\Models\EmployeeSubTask;
use App\Models\SubTask;
use App\Services\EmployeeTasks\EmployeeTaskAssigneeService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckTaskOwnership
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, $taskType, $field = 'sub_task_id'): Response
    {
        // Map model names
        $models = [
            'employee' => EmployeeSubTask::class,
            'special'  => SubTask::class,
        ];

        if (!isset($models[$taskType])) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.invalid_task_type'),
            ], 200);
        }

        $model = $models[$taskType];
        $taskId = $request->input($field); // take from body instead of route

        $subTask = $model::find($taskId);

        if (!$subTask) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.subtask_not_found'),
            ], 200);
        }

        $employeeId = (int) (auth()->user()?->employee?->id ?? 0);
        $parentTask = $subTask->{$taskType . 'Task'};

        if ($taskType === 'employee') {
            if (! $parentTask || $employeeId <= 0 || ! app(EmployeeTaskAssigneeService::class)->isAssignee($parentTask, $employeeId)) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.unauthorized'),
                ], 200);
            }
        } elseif (! $parentTask || $parentTask->employee_id !== $employeeId) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.unauthorized'),
            ], 200);
        }

        return $next($request);
    }
}
