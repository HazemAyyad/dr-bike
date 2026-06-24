<?php

namespace App\Http\Controllers\API\Store;

use App\Models\AppSetting;

class StoreSettingsController extends StoreBaseController
{
    public function checkSetting()
    {
        $settings = AppSetting::query()->pluck('value', 'key');

        $data = [
            'id' => 1,
            'isClose' => filter_var($settings->get('store_is_close', false), FILTER_VALIDATE_BOOL),
            'message' => (string) ($settings->get('store_close_message') ?? $settings->get('message') ?? ''),
            'call' => (string) ($settings->get('store_call') ?? $settings->get('call') ?? ''),
            'whatsApp' => (string) ($settings->get('store_whatsapp') ?? $settings->get('whatsApp') ?? $settings->get('whatsapp') ?? ''),
            'instagram' => (string) ($settings->get('store_instagram') ?? $settings->get('instagram') ?? ''),
            'twitter' => (string) ($settings->get('store_twitter') ?? $settings->get('twitter') ?? ''),
        ];

        return response()->json([
            'data' => $data,
            'isSuccess' => true,
            'error' => null,
            'isFailure' => false,
        ]);
    }
}
