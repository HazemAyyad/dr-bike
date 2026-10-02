<?php

namespace Tests\Feature\OnlineStore;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\OnlineStoreFixtureFactory;
use Tests\TestCase;

class BannerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (filter_var(env('ONLINE_STORE_DUMP_DERIVED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Fresh-schema tests cannot alter restored dump-derived state.');
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-02 12:00:00', config('app.timezone')));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_banner_action_schedule_url_and_deterministic_active_window(): void
    {
        OnlineStoreFixtureFactory::createAuthenticatedAdminActor();
        $this->postJson('/api/online-store/home-sections', ['key' => 'hero', 'section_type' => 'hero', 'selection_mode' => 'dedicated_banners'])->assertCreated();
        $this->postJson('/api/online-store/banners', ['image_path' => 'fixture.jpg', 'action_type' => 'url', 'action_url' => 'javascript:alert(1)'])->assertUnprocessable();
        $this->postJson('/api/online-store/banners', ['image_path' => 'fixture.jpg', 'action_type' => 'listing', 'action_target_id' => 999999])->assertUnprocessable();
        $this->postJson('/api/online-store/banners', ['image_path' => 'fixture.jpg', 'action_type' => 'none', 'action_url' => 'https://example.invalid'])->assertUnprocessable();
        $this->postJson('/api/online-store/banners', ['image_path' => 'fixture.jpg', 'action_type' => 'none', 'starts_at' => '2026-10-03 12:00:00', 'ends_at' => '2026-10-02 12:00:00'])->assertUnprocessable();

        $second = $this->postJson('/api/online-store/banners', ['image_path' => 'second.jpg', 'action_type' => 'url', 'action_url' => 'https://example.invalid/two', 'is_active' => true, 'sort_order' => 1, 'starts_at' => '2026-10-02 10:00:00', 'ends_at' => '2026-10-02 14:00:00'])->assertCreated()->json('data');
        $first = $this->postJson('/api/online-store/banners', ['image_path' => 'first.jpg', 'action_type' => 'none', 'is_active' => true, 'sort_order' => 1, 'starts_at' => '2026-10-02 10:00:00', 'ends_at' => '2026-10-02 14:00:00'])->assertCreated()->json('data');
        $this->postJson('/api/online-store/banners', ['image_path' => 'future.jpg', 'action_type' => 'none', 'is_active' => true, 'sort_order' => 0, 'starts_at' => '2026-10-03 10:00:00'])->assertCreated();

        $this->assertSame('2026-10-02T07:00:00.000000Z', $second['starts_at']);

        $items = collect($this->getJson('/OnlineStore/Home')->assertOk()->json('data.sections.0.items'));
        $this->assertSame(collect([$second['id'], $first['id']])->sort()->values()->all(), $items->pluck('id')->all());
        $this->assertNotContains('future.jpg', $items->pluck('image_path')->all());
    }
}
