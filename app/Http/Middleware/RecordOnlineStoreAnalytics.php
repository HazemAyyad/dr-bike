<?php

namespace App\Http\Middleware;

use App\Services\OnlineStore\OnlineStoreAnalyticsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RecordOnlineStoreAnalytics
{
    public function __construct(private readonly OnlineStoreAnalyticsService $analytics) {}

    public function handle(Request $request, Closure $next, string $metric): Response
    {
        $response = $next($request);
        if ($response->getStatusCode() >= 400) {
            return $response;
        }

        $increments = [$metric => 1];
        if ($metric === 'store_visits') {
            $payload = method_exists($response, 'getData') ? $response->getData(true) : [];
            $sections = data_get($payload, 'data.sections', []);
            $increments['section_views'] = is_array($sections)
                ? count(array_filter($sections, fn ($section) => ($section['section_type'] ?? null) === 'maintenance'
                    || count($section['items'] ?? []) > 0))
                : 0;
        }
        $this->analytics->record($request, $increments, $metric === 'store_visits');

        return $response;
    }
}
