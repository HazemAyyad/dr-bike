<?php

namespace Tests\Feature\OnlineStore;

use App\Models\OnlineStore\OnlineStoreSettings;
use App\Services\OnlineStore\OnlineStoreSettingsService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Tests\Support\OnlineStoreFixtureFactory;
use Tests\TestCase;

class OnlineStoreSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (filter_var(env('ONLINE_STORE_DUMP_DERIVED_SCHEMA_READY', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Fresh-schema tests cannot alter restored dump-derived state.');
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    public function test_singleton_typed_settings_and_operating_precedence_are_deterministic(): void
    {
        $service = app(OnlineStoreSettingsService::class);
        $actor = OnlineStoreFixtureFactory::createAdminActor();
        $settings = $service->save($actor, [
            'store_enabled' => false,
            'maintenance_mode' => true,
            'checkout_enabled' => true,
            'cod_enabled' => true,
            'guest_browsing_enabled' => true,
            'minimum_order' => 100,
            'enabled_languages' => ['ar', 'en'],
            'cancellation_policy_translations' => ['ar' => 'سياسة'],
            'out_of_stock_behavior' => 'visible_non_purchasable',
            'low_stock_threshold' => 3,
        ]);

        $this->assertSame(OnlineStoreSettings::SINGLETON_ID, $settings->id);
        $this->assertSame('disabled', $service->operatingState($settings)['state']);
        $settings = $service->save($actor, ['store_enabled' => true]);
        $this->assertSame('maintenance', $service->operatingState($settings)['state']);
        $settings = $service->save($actor, ['maintenance_mode' => false, 'checkout_enabled' => false]);
        $this->assertSame('browse_only', $service->operatingState($settings)['state']);
        $this->assertTrue($service->operatingState($settings)['browsing_allowed']);
        $settings = $service->save($actor, ['checkout_enabled' => true]);
        $this->assertSame('open', $service->operatingState($settings)['state']);
        $this->assertTrue($service->operatingState($settings)['cod_allowed']);
        $this->assertSame(['ar', 'en'], $settings->enabled_languages);
        $this->assertSame('visible_non_purchasable', $settings->out_of_stock_behavior);
        $this->assertDatabaseCount('online_store_settings', 1);
    }

    public function test_checkout_cod_and_minimum_order_gates_are_server_enforced(): void
    {
        $service = app(OnlineStoreSettingsService::class);
        $actor = OnlineStoreFixtureFactory::createAdminActor();
        $service->save($actor, ['store_enabled' => true, 'maintenance_mode' => false, 'checkout_enabled' => true, 'cod_enabled' => false, 'minimum_order' => 100, 'enabled_languages' => ['ar']]);

        foreach ([[150, 'cash'], [50, 'credit']] as [$total, $type]) {
            try {
                $service->assertCheckoutAllowed($total, $type);
                $this->fail('Configured checkout restriction must be enforced.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }

    public function test_legacy_settings_shape_maps_typed_values_without_new_fields(): void
    {
        app(OnlineStoreSettingsService::class)->save(OnlineStoreFixtureFactory::createAdminActor(), [
            'store_enabled' => false,
            'support_phone' => 'fixture-support',
            'whatsapp' => 'fixture-whatsapp',
            'enabled_languages' => ['ar'],
        ]);

        $this->postJson('/Settings/CheckSetting')->assertOk()->assertJsonStructure([
            'data' => ['id', 'isClose', 'message', 'call', 'whatsApp', 'instagram', 'twitter'],
            'isSuccess', 'error', 'isFailure',
        ])->assertJsonPath('data.isClose', true)
            ->assertJsonPath('data.call', 'fixture-support')
            ->assertJsonPath('data.whatsApp', 'fixture-whatsapp');
    }
}
