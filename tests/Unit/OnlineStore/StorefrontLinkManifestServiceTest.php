<?php

namespace Tests\Unit\OnlineStore;

use App\Services\OnlineStore\StorefrontLinkManifestService;
use Tests\TestCase;

class StorefrontLinkManifestServiceTest extends TestCase
{
    public function test_android_manifest_is_not_fabricated_without_a_signing_fingerprint(): void
    {
        config()->set('storefront.links.android_package', 'com.mahm.doctor_bike');
        config()->set('storefront.links.android_sha256_fingerprints', []);

        $this->assertSame([], (new StorefrontLinkManifestService)->android());
    }

    public function test_android_manifest_uses_configured_package_and_fingerprints(): void
    {
        config()->set('storefront.links.android_package', 'com.mahm.doctor_bike');
        config()->set('storefront.links.android_sha256_fingerprints', ['aa:bb', ' CC:DD ']);

        $this->assertSame([[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => [
                'namespace' => 'android_app',
                'package_name' => 'com.mahm.doctor_bike',
                'sha256_cert_fingerprints' => ['AA:BB', 'CC:DD'],
            ],
        ]], (new StorefrontLinkManifestService)->android());
    }

    public function test_ios_manifest_uses_team_bundle_and_both_deployment_paths(): void
    {
        config()->set('storefront.links.ios_team_id', 'TEAM123');
        config()->set('storefront.links.ios_bundle_id', 'com.hazemAyad.doctorBike');

        $this->assertSame([
            'applinks' => [
                'apps' => [],
                'details' => [[
                    'appID' => 'TEAM123.com.hazemAyad.doctorBike',
                    'paths' => ['/store/products/*', '/public/store/products/*'],
                ]],
            ],
        ], (new StorefrontLinkManifestService)->ios());
    }
}
