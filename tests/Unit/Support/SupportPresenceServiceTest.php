<?php

namespace Tests\Unit\Support;

use App\Services\Support\SupportPresenceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SupportPresenceServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_support_presence_expires_when_heartbeats_stop(): void
    {
        $now = CarbonImmutable::now();
        CarbonImmutable::setTestNow($now);
        $presence = app(SupportPresenceService::class);

        $presence->touch(42);
        $online = $presence->snapshot(42);

        $this->assertTrue($online['support_is_online']);
        $this->assertSame($now->toIso8601String(), $online['support_last_seen_at']);
        $this->assertSame(
            $now->addSeconds(SupportPresenceService::ONLINE_WINDOW_SECONDS)->toIso8601String(),
            $online['support_presence_expires_at']
        );

        CarbonImmutable::setTestNow(
            $now->addSeconds(SupportPresenceService::ONLINE_WINDOW_SECONDS + 1)
        );
        $this->assertFalse($presence->snapshot(42)['support_is_online']);
    }
}
