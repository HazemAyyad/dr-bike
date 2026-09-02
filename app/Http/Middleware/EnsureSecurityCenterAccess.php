<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSecurityCenterAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->session()->get('security_center_authenticated', false)) {
            return redirect()->route('security-center.login');
        }

        return $next($request);
    }
}
