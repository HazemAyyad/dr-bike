<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdmsDebugLogger
{
    public static function log(Request $request, string $routeLabel): void
    {
        $payload = [
            'route' => $routeLabel,
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'ip' => $request->ip(),
            'headers' => $request->headers->all(),
            'query' => $request->query(),
            'body' => $request->getContent(),
        ];

        try {
            Log::channel('daily')->info('ADMS REQUEST', $payload);
        } catch (\Throwable) {
            Log::info('ADMS REQUEST', $payload);
        }

        $line = json_encode(
            array_merge(['logged_at' => now()->toIso8601String()], $payload),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ).PHP_EOL;

        @file_put_contents(storage_path('logs/adms-debug.log'), $line, FILE_APPEND | LOCK_EX);
    }

    public static function logOutcome(string $routeLabel, array $data): void
    {
        $payload = array_merge(['route' => $routeLabel, 'type' => 'outcome'], $data);

        try {
            Log::channel('daily')->info('ADMS OUTCOME', $payload);
        } catch (\Throwable) {
            Log::info('ADMS OUTCOME', $payload);
        }

        $line = json_encode(
            array_merge(['logged_at' => now()->toIso8601String()], $payload),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ).PHP_EOL;

        @file_put_contents(storage_path('logs/adms-debug.log'), $line, FILE_APPEND | LOCK_EX);
    }
}
