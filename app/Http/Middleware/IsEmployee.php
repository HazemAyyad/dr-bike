<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IsEmployee
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
       $user = $request->user();

        if (!$user || $user->type !== 'employee') {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Employees only.',
            ], 200);
        }

        return $next($request);
    }    
}
