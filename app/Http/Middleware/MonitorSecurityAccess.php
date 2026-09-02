<?php

namespace App\Http\Middleware;

use App\Services\SecurityAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class MonitorSecurityAccess
{
    public function __construct(private readonly SecurityAccessService $security) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->security->record($request, 500);
            throw $exception;
        }

        $this->security->record($request, $response->getStatusCode());

        return $response;
    }
}
