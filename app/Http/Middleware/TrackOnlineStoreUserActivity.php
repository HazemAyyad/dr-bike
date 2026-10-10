<?php

namespace App\Http\Middleware;

use App\Services\OnlineStore\StoreActivityTracker;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class TrackOnlineStoreUserActivity
{
    public function __construct(private readonly StoreActivityTracker $activity) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->activity->actor($request);

        return $next($request);
    }
}
