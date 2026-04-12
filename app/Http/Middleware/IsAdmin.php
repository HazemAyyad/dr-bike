<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || $user->type !== 'admin') {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Admins only.',
            ], 200);
        }

        return $next($request);
    }
}
