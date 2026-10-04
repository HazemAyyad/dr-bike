<?php

namespace Tests\Feature\OnlineStore;

use App\Models\AppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreUpdateCheckTest extends TestCase
{
    use RefreshDatabase {
        refreshTestDatabase as baseRefreshTestDatabase;
    }

    protected function refreshTestDatabase(): void
    {
        $this->requireDisposableDatabase();
        if (filter_var(env('ONLINE_STORE_PREMIGRATED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            $this->beginDatabaseTransaction();

            return;
        }
        $this->baseRefreshTestDatabase();
    }

    public function test_existing_admin_defaults_and_windows_support_remain_unchanged(): void
    {
        $this->getJson('/api/app/update-check?'.http_build_query([
            'app' => 'admin', 'platform' => 'windows', 'current_version' => '1.0.0', 'current_build' => 1,
        ]))->assertOk()->assertJsonPath('app', 'admin')->assertJsonPath('platform', 'windows');
    }

    public function test_store_supports_android_and_ios_but_not_windows(): void
    {
        foreach (['android', 'ios'] as $platform) {
            $this->getJson('/api/app/update-check?'.http_build_query([
                'app' => 'store', 'platform' => $platform, 'current_version' => '2.2.0', 'current_build' => 9,
            ]))->assertOk()
                ->assertJsonPath('app', 'store')
                ->assertJsonPath('platform', $platform)
                ->assertJsonPath('has_update', false)
                ->assertJsonPath('force_update', false);
        }

        $this->getJson('/api/app/update-check?'.http_build_query([
            'app' => 'store', 'platform' => 'windows', 'current_version' => '2.2.0', 'current_build' => 9,
        ]))->assertOk()->assertJsonPath('status', 'error')->assertJsonValidationErrors('platform');
    }

    public function test_store_settings_are_independent_and_can_enforce_configured_minimum_build(): void
    {
        AppSetting::set(AppSetting::KEY_APP_UPDATE_STORE_ANDROID_ACTIVE, '1');
        AppSetting::set(AppSetting::KEY_APP_UPDATE_STORE_ANDROID_LATEST_VERSION, '2.2.1');
        AppSetting::set(AppSetting::KEY_APP_UPDATE_STORE_ANDROID_LATEST_BUILD, '10');
        AppSetting::set(AppSetting::KEY_APP_UPDATE_STORE_ANDROID_MINIMUM_BUILD, '10');

        $this->getJson('/api/app/update-check?'.http_build_query([
            'app' => 'store', 'platform' => 'android', 'current_version' => '2.2.0', 'current_build' => 9,
        ]))->assertOk()
            ->assertJsonPath('app', 'store')
            ->assertJsonPath('has_update', true)
            ->assertJsonPath('force_update', true)
            ->assertJsonPath('minimum_build', 10);
    }
}
