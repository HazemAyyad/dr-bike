<?php

namespace App\Services\OnlineStore;

final class StorefrontLinkManifestService
{
    public function android(): array
    {
        $fingerprints = array_values(array_filter(array_map(
            static fn ($value) => strtoupper(trim((string) $value)),
            (array) config('storefront.links.android_sha256_fingerprints', []),
        )));
        if ($fingerprints === []) {
            return [];
        }

        return [[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => [
                'namespace' => 'android_app',
                'package_name' => (string) config('storefront.links.android_package'),
                'sha256_cert_fingerprints' => $fingerprints,
            ],
        ]];
    }

    public function ios(): array
    {
        $teamId = trim((string) config('storefront.links.ios_team_id'));
        $bundleId = trim((string) config('storefront.links.ios_bundle_id'));
        $details = $teamId !== '' && $bundleId !== ''
            ? [[
                'appID' => $teamId.'.'.$bundleId,
                'paths' => ['/store/products/*', '/public/store/products/*'],
            ]]
            : [];

        return [
            'applinks' => [
                'apps' => [],
                'details' => $details,
            ],
        ];
    }
}
