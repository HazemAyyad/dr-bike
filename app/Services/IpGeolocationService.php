<?php

namespace App\Services;

use App\Models\SecurityAccessVisitor;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class IpGeolocationService
{
    /**
     * Resolve and persist location data for a limited number of IPs.
     *
     * @return array{requested:int,updated:int,failed:int}
     */
    public function refreshMissing(int $limit = 15): array
    {
        if (! config('security_center.geolocation.enabled', true)) {
            return ['requested' => 0, 'updated' => 0, 'failed' => 0];
        }

        $refreshBefore = now()->subDays(max(1, (int) config('security_center.geolocation.refresh_days', 30)));
        $ips = SecurityAccessVisitor::query()
            ->where(function ($query) use ($refreshBefore) {
                $query->whereNull('geo_updated_at')
                    ->orWhere('geo_updated_at', '<', $refreshBefore);
            })
            ->orderByDesc('last_seen_at')
            ->pluck('ip_address')
            ->unique()
            ->filter(fn (string $ip) => $this->isPublicIp($ip))
            ->take(max(1, min($limit, 30)))
            ->values();

        if ($ips->isEmpty()) {
            return ['requested' => 0, 'updated' => 0, 'failed' => 0];
        }

        $endpoint = rtrim((string) config('security_center.geolocation.endpoint', 'https://ipwho.is'), '/');

        try {
            $responses = Http::pool(fn (Pool $pool) => $ips
                ->map(fn (string $ip) => $pool
                    ->as(hash('sha256', $ip))
                    ->acceptJson()
                    ->connectTimeout(3)
                    ->timeout(6)
                    ->get($endpoint.'/'.rawurlencode($ip)))
                ->all());
        } catch (Throwable $exception) {
            SecurityAccessVisitor::query()->whereIn('ip_address', $ips)->update([
                'geo_updated_at' => now(),
                'geo_error' => mb_substr($exception->getMessage(), 0, 255),
            ]);

            return ['requested' => $ips->count(), 'updated' => 0, 'failed' => $ips->count()];
        }

        $updated = 0;
        $failed = 0;
        foreach ($ips as $ip) {
            $response = $responses[hash('sha256', $ip)] ?? null;
            $payload = $response instanceof Response ? $response->json() : null;
            $success = $response instanceof Response
                && $response->successful()
                && is_array($payload)
                && ($payload['success'] ?? false) === true;

            if (! $success) {
                $failed++;
                SecurityAccessVisitor::query()->where('ip_address', $ip)->update([
                    'geo_updated_at' => now(),
                    'geo_error' => mb_substr(
                        (string) (is_array($payload) ? ($payload['message'] ?? 'تعذر تحديد الموقع') : 'تعذر الاتصال بخدمة الموقع'),
                        0,
                        255
                    ),
                ]);

                continue;
            }

            $updated++;
            SecurityAccessVisitor::query()->where('ip_address', $ip)->update([
                'country' => mb_substr((string) ($payload['country'] ?? ''), 0, 120) ?: null,
                'country_code' => mb_substr((string) ($payload['country_code'] ?? ''), 0, 8) ?: null,
                'region' => mb_substr((string) ($payload['region'] ?? ''), 0, 150) ?: null,
                'city' => mb_substr((string) ($payload['city'] ?? ''), 0, 150) ?: null,
                'isp' => mb_substr((string) data_get($payload, 'connection.isp', ''), 0, 255) ?: null,
                'geo_updated_at' => now(),
                'geo_error' => null,
            ]);
        }

        return ['requested' => $ips->count(), 'updated' => $updated, 'failed' => $failed];
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
