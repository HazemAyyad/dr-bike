<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\AdminSectionAccess;
use Closure;
use Illuminate\Http\Request;

class EnforceAdminSections
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user instanceof User && ! app(AdminSectionAccess::class)->allows($user, $request)) {
            return response()->json(['status' => 'error', 'message' => 'ليس لديك صلاحية الوصول إلى هذا القسم.'], 403);
        }

        return $next($request);
    }
}
