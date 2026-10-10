<?php

namespace App\Services\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class SupportPresenceService
{
    public const ONLINE_WINDOW_SECONDS = 75;

    public function touch(int $conversationId): void
    {
        if ($conversationId < 1) {
            return;
        }

        $lastSeen = CarbonImmutable::now();
        Cache::put(
            $this->key($conversationId),
            $lastSeen->toIso8601String(),
            $lastSeen->addSeconds(self::ONLINE_WINDOW_SECONDS)
        );
    }

    /**
     * @return array{support_is_online: bool, support_last_seen_at: ?string, support_presence_expires_at: ?string}
     */
    public function snapshot(int $conversationId): array
    {
        $value = $conversationId > 0 ? Cache::get($this->key($conversationId)) : null;
        try {
            $lastSeen = is_string($value) ? CarbonImmutable::parse($value) : null;
        } catch (Throwable) {
            Cache::forget($this->key($conversationId));
            $lastSeen = null;
        }
        $expiresAt = $lastSeen?->addSeconds(self::ONLINE_WINDOW_SECONDS);

        return [
            'support_is_online' => $expiresAt?->isFuture() ?? false,
            'support_last_seen_at' => $lastSeen?->toIso8601String(),
            'support_presence_expires_at' => $expiresAt?->toIso8601String(),
        ];
    }

    private function key(int $conversationId): string
    {
        return 'support:conversation:'.$conversationId.':agent-presence';
    }
}
