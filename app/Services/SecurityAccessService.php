<?php

namespace App\Services;

use App\Models\SecurityAccessVisitor;
use App\Models\SecurityIpBlock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SecurityAccessService
{
    private static ?bool $tablesReady = null;

    public function tablesReady(): bool
    {
        if (self::$tablesReady !== null) {
            return self::$tablesReady;
        }

        try {
            return self::$tablesReady = Schema::hasTable('security_ip_blocks')
                && Schema::hasTable('security_access_visitors');
        } catch (Throwable) {
            return self::$tablesReady = false;
        }
    }

    public function isBlocked(string $ip): bool
    {
        if (! $this->tablesReady() || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        try {
            return (bool) Cache::remember(
                $this->blockCacheKey($ip),
                max(5, (int) config('security_center.block_cache_seconds', 30)),
                function () use ($ip): bool {
                    $block = SecurityIpBlock::query()
                        ->where('ip_address', $ip)
                        ->where('active', true)
                        ->first();

                    if ($block === null) {
                        return false;
                    }

                    if ($block->expires_at !== null && $block->expires_at->isPast()) {
                        $block->update(['active' => false]);

                        return false;
                    }

                    return true;
                }
            );
        } catch (Throwable) {
            // تعطل مركز الأمان يجب ألا يوقف التطبيق.
            return false;
        }
    }

    public function forgetBlock(string $ip): void
    {
        try {
            Cache::forget($this->blockCacheKey($ip));
        } catch (Throwable) {
            // لا حاجة لإيقاف الإجراء إذا تعذر تنظيف الكاش.
        }
    }

    public function record(Request $request, int $status, bool $force = false): void
    {
        if (! $this->tablesReady()) {
            return;
        }

        $ip = (string) $request->ip();
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return;
        }

        $user = $request->user();
        $userAgent = mb_substr((string) $request->userAgent(), 0, 1000);
        $visitorKey = hash('sha256', implode('|', [
            $ip,
            $user?->getAuthIdentifier() ?? 'guest',
            $userAgent,
        ]));

        if (! $force && ! $this->shouldSample($visitorKey, $status)) {
            return;
        }

        try {
            $visitor = SecurityAccessVisitor::query()->firstOrNew([
                'visitor_key' => $visitorKey,
            ]);
            $now = now();

            if (! $visitor->exists) {
                $visitor->first_seen_at = $now;
                $visitor->observations = 0;
            }

            $visitor->fill([
                'ip_address' => $ip,
                'user_id' => $user?->getAuthIdentifier(),
                'user_name' => $user?->name,
                'user_type' => $user?->type,
                'device_type' => $this->deviceType($userAgent),
                'user_agent' => $userAgent,
                'last_method' => $request->method(),
                'last_route' => mb_substr($request->route()?->getName() ?: $request->path(), 0, 255),
                'last_status' => $status,
                'last_seen_at' => $now,
            ]);
            $visitor->observations = ((int) $visitor->observations) + 1;
            $visitor->save();
        } catch (Throwable) {
            // التسجيل تشخيصي فقط ويجب ألا يؤثر على استجابة التطبيق.
        }
    }

    private function shouldSample(string $visitorKey, int $status): bool
    {
        $seconds = max(5, (int) config('security_center.sample_seconds', 20));
        $bucket = $status >= 400 ? "error:{$status}" : 'normal';

        try {
            return Cache::add("security-center:sample:{$visitorKey}:{$bucket}", true, $seconds);
        } catch (Throwable) {
            return true;
        }
    }

    private function blockCacheKey(string $ip): string
    {
        return 'security-center:block:'.hash('sha256', $ip);
    }

    private function deviceType(string $userAgent): string
    {
        $agent = mb_strtolower($userAgent);

        return match (true) {
            str_contains($agent, 'android') => 'Android',
            str_contains($agent, 'iphone'), str_contains($agent, 'ipad') => 'iOS',
            str_contains($agent, 'dart') => 'تطبيق Flutter',
            str_contains($agent, 'windows') => 'Windows',
            str_contains($agent, 'macintosh') => 'macOS',
            str_contains($agent, 'linux') => 'Linux',
            $agent === '' => 'غير معروف',
            default => 'متصفح/جهاز آخر',
        };
    }
}
