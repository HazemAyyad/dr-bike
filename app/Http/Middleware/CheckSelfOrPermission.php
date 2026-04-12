<?php

namespace App\Http\Middleware;

use App\Models\EmployeeTask;
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
    public function handle(Request $request ,Closure $next, $modelName, $filed ='employee_task_id' , ...$permissions): Response
    {
    
        $user = $request->user();

        $models = [
            'employeeTask' => EmployeeTask::class,

        ];

        // Always allow admins
        if ($user && $user->type === 'admin') {
            return $next($request);
        }

        // If it's the same employee requesting their own details
        if ($user && $user->type === 'employee') {
            $model = $models[$modelName];
            $instance = $model::find($request->input($filed));
            if ($instance && $user->employee && $user->employee->id == $instance->employee_id) {
                return $next($request);
            }

            // Otherwise, fall back to permission check
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
            'message' => 'Unauthorized. Requires self or one of: ' . implode(', ', $permissions),
        ], 200);
    }
    }

