<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InventoryWebAccess
{
    public function handle(Request $request, Closure $next, string $role = '')
    {
        $user = Auth::guard('inventory_web')->user();
        if (! $user || ! in_array($user->inventory_web_role, ['admin', 'counter'], true) || $user->is_blocked) {
            Auth::guard('inventory_web')->logout();

            return $request->expectsJson() ? response()->json(['message' => 'انتهت جلسة الدخول.'], 401) : redirect()->route('inventory-web.login');
        }
        abort_if($role === 'admin' && $user->inventory_web_role !== 'admin', 403, 'هذه العملية للمسؤول فقط.');
        $request->setUserResolver(fn () => $user);
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
