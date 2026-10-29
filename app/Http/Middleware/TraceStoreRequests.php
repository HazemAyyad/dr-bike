<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class TraceStoreRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->header('X-Request-ID', '');
        $id = Str::isUuid($incoming) ? $incoming : (string) Str::uuid();
        $request->attributes->set('store_request_id', $id);
        $start = hrtime(true);
        $status = 500;
        try {
            try {
                $response = $next($request);
            } catch (Throwable $exception) {
                $handler = app(\Illuminate\Contracts\Debug\ExceptionHandler::class);
                $handler->report($exception);
                $response = $handler->render($request, $exception);
            }
            $status = $response->getStatusCode();
            $response->headers->set('X-Request-ID', $id);

            return $response;
        } finally {
            Log::info('store_api_request', [
                'request_id' => $id,
                'route' => $request->route()?->uri(),
                'status' => $status,
                'duration_ms' => round((hrtime(true) - $start) / 1_000_000, 2),
            ]);
        }
    }
}
