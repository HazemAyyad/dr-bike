<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
     public function handle(Request $request, Closure $next, ...$permissions): Response
    {
        $user = $request->user();

        // Admins always allowed
        if ($user && $user->type === 'admin') {
            return $next($request);
        }

        if ($user && $user->type === 'employee') {
            // Loop through all provided permissions
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
            'message' => 'Unauthorized. Requires one of: ' . implode(', ', $permissions),
        ], 200);
    }

}