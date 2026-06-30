<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppSetting;
use App\Services\WhatsApp\WhatsAppCloudApiService;
use Illuminate\Http\Request;

class WhatsAppSettingsController extends Controller
{
    public function show(WhatsAppCloudApiService $service)
    {
        $configurationError = null;
        try {
            $service->validateConfig();
        } catch (\Throwable $e) {
            $configurationError = $e->getMessage();
        }
        return response()->json([
            'status' => 'success',
            'settings' => WhatsAppSetting::query()
                ->whereRaw('LOWER(`key`) NOT LIKE ?', ['%token%'])
                ->whereRaw('LOWER(`key`) NOT LIKE ?', ['%secret%'])
                ->whereRaw('LOWER(`key`) NOT LIKE ?', ['%password%'])
                ->pluck('value', 'key'),
            'connection' => [
                'configured' => $configurationError === null,
                'message' => $configurationError ?: 'WhatsApp Cloud API is configured.',
                'api_version' => config('whatsapp.api_version'),
                'phone_number_id' => $this->mask(config('whatsapp.phone_number_id')),
                'business_account_id' => $this->mask(config('whatsapp.business_account_id')),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'settings' => 'required|array',
            'settings.*.key' => 'required|string|max:100|not_in:access_token,verify_token,WHATSAPP_ACCESS_TOKEN,WHATSAPP_VERIFY_TOKEN',
            'settings.*.value' => 'nullable|string|max:10000',
            'settings.*.type' => 'nullable|in:string,boolean,integer,json',
        ]);
        foreach ($data['settings'] as $setting) {
            if (preg_match('/token|secret|password|access[_-]?key/i', $setting['key'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Sensitive credentials must only be configured in Laravel .env.',
                ], 422);
            }
            WhatsAppSetting::query()->updateOrCreate(
                ['key' => $setting['key']],
                ['value' => $setting['value'] ?? null, 'type' => $setting['type'] ?? 'string']
            );
        }
        return $this->show(app(WhatsAppCloudApiService::class));
    }

    private function mask(?string $value): ?string
    {
        if (! $value) return null;
        return strlen($value) <= 6 ? str_repeat('*', strlen($value)) : substr($value, 0, 3).str_repeat('*', max(strlen($value) - 6, 3)).substr($value, -3);
    }
}
