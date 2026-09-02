<?php

namespace App\Http\Middleware;

use App\Services\SecurityAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceSecurityIpBlock
{
    public function __construct(private readonly SecurityAccessService $security) {}

    public function handle(Request $request, Closure $next): Response
    {
        $ip = (string) $request->ip();

        if ($this->security->isBlocked($ip)) {
            $this->security->record($request, 403, true);

            return response()->json([
                'status' => 'error',
                'code' => 'ip_blocked',
                'message' => 'تم حظر عنوان الشبكة من الوصول إلى التطبيق.',
            ], 403);
        }

        return $next($request);
    }
}
