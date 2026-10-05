<?php

namespace App\Http\Controllers\API\Store;

use App\Models\AppSetting;
use App\Services\OnlineStore\OnlineStoreSettingsService;

class StoreSettingsController extends StoreBaseController
{
    public function checkSetting(OnlineStoreSettingsService $onlineStoreSettings)
    {
        $settings = AppSetting::query()->pluck('value', 'key');

        $typed = $onlineStoreSettings->current(create: false);
        $state = $typed ? $onlineStoreSettings->operatingState($typed) : null;
        $legacyClosed = filter_var($settings->get('store_is_close', false), FILTER_VALIDATE_BOOL);
        $closed = $state ? ! $state['browsing_allowed'] : $legacyClosed;
        $message = (string) ($settings->get('store_close_message') ?? $settings->get('message') ?? '');
        if ($state && $state['state'] === 'maintenance') {
            $message = 'Store maintenance is in progress.';
        } elseif ($state && $state['state'] === 'disabled') {
            $message = 'Store is currently unavailable.';
        }

        $data = [
            'id' => 1,
            'isClose' => $closed,
            'message' => $message,
            'call' => (string) ($typed?->support_phone ?? $settings->get('store_call') ?? $settings->get('call') ?? ''),
            'whatsApp' => (string) ($typed?->whatsapp ?? $settings->get('store_whatsapp') ?? $settings->get('whatsApp') ?? $settings->get('whatsapp') ?? ''),
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
