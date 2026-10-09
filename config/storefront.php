<?php

return [
    'links' => [
        'android_package' => env('STORE_ANDROID_PACKAGE', 'com.mahm.doctor_bike'),
        'android_sha256_fingerprints' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('STORE_ANDROID_SHA256_CERT_FINGERPRINTS', '')),
        ))),
        'ios_team_id' => env('STORE_IOS_TEAM_ID', ''),
        'ios_bundle_id' => env('STORE_IOS_BUNDLE_ID', 'com.hazemAyad.doctorBike'),
    ],
];
