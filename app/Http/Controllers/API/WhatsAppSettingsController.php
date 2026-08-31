<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppSetting;
use App\Models\WhatsAppAccount;
use App\Models\EmployeeDetail;
use App\Models\EmployeePermission;
use App\Models\Permission;
use App\Services\WhatsApp\WhatsAppCloudApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class WhatsAppSettingsController extends Controller
{
    private const SOCIAL_PERMISSIONS = [
        'main' => 'Messages Section',
        'whatsapp' => 'Social Center WhatsApp',
        'facebook' => 'Social Center Facebook',
        'instagram' => 'Social Center Instagram',
    ];
    public function show(Request $request, WhatsAppCloudApiService $service)
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
            'channels' => $this->channels($service),
            'meta_app_status' => $this->metaAppStatus(),
            'can_manage_employees' => $request->user()?->type === 'admin',
            'employees' => $request->user()?->type === 'admin'
                ? $this->employeesWithAccess()
                : [],
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
        return $this->show($request, app(WhatsAppCloudApiService::class));
    }

    public function updateEmployees(Request $request)
    {
        abort_unless($request->user()?->type === 'admin', 403);
        $data = $request->validate([
            'employee_channel_access' => 'present|array',
            'employee_channel_access.*' => 'array',
            'employee_channel_access.*.*' => 'in:main,whatsapp,facebook,instagram',
        ]);
        $access = collect($data['employee_channel_access']);
        $employeeIds = $access->keys()->map(fn ($id) => (int) $id);
        abort_if(EmployeeDetail::query()->whereIn('id', $employeeIds)->count() !== $employeeIds->unique()->count(), 422);
        $permissions = Permission::query()->whereIn('name_en', array_values(self::SOCIAL_PERMISSIONS))->get()->keyBy('name_en');
        abort_if($permissions->count() !== count(self::SOCIAL_PERMISSIONS), 422, 'Social center permissions are not migrated.');
        $permissionIds = $permissions->pluck('id');

        DB::transaction(function () use ($access, $permissions, $permissionIds) {
            EmployeePermission::query()
                ->whereIn('permission_id', $permissionIds)
                ->delete();
            foreach ($access as $employeeId => $channels) {
                $channels = collect($channels)->unique()->values();
                if ($channels->isEmpty()) continue;
                if ($channels->intersect(['whatsapp', 'facebook', 'instagram'])->isNotEmpty() && ! $channels->contains('main')) {
                    $channels->prepend('main');
                }
                foreach ($channels as $key) {
                    EmployeePermission::query()->create([
                        'employee_id' => (int) $employeeId,
                        'permission_id' => $permissions[self::SOCIAL_PERMISSIONS[$key]]->id,
                    ]);
                }
            }
        });

        return response()->json([
            'status' => 'success',
            'employees' => $this->employeesWithAccess(),
        ]);
    }

    private function employeesWithAccess(): array
    {
        $permissionIds = Permission::query()
            ->whereIn('name_en', array_values(self::SOCIAL_PERMISSIONS))
            ->pluck('id', 'name_en');

        return EmployeeDetail::query()
            ->with('user:id,name,phone')
            ->whereHas('user', fn ($query) => $query->where('type', 'employee'))
            ->orderBy('id')
            ->get(['id', 'user_id', 'job_title'])
            ->map(fn (EmployeeDetail $employee) => [
                'id' => $employee->id,
                'name' => $employee->user?->name ?: 'موظف #'.$employee->id,
                'phone' => $employee->user?->phone,
                'job_title' => $employee->job_title,
                'has_social_center_access' => $employee->permissions()
                    ->where('permission_id', $permissionIds[self::SOCIAL_PERMISSIONS['main']] ?? 0)->exists(),
                'channel_access' => collect(['whatsapp', 'facebook', 'instagram'])->mapWithKeys(
                    fn ($channel) => [$channel => $employee->permissions()
                        ->where('permission_id', $permissionIds[self::SOCIAL_PERMISSIONS[$channel]] ?? 0)->exists()]
                )->all(),
            ])
            ->all();
    }

    private function channels(WhatsAppCloudApiService $service): array
    {
        $whatsAppPhone = null;
        try {
            $whatsAppPhone = $service->businessPhoneNumber();
        } catch (\Throwable) {
            $whatsAppPhone = filled(config('whatsapp.display_phone_number'))
                ? preg_replace('/\D+/', '', (string) config('whatsapp.display_phone_number'))
                : null;
        }

        $meta = $this->metaPageProfile();
        $instagram = data_get($meta, 'instagram_business_account') ?: [];
        $instagramUsername = config('meta_messaging.instagram_username') ?: data_get($instagram, 'username');
        $instagramUrl = config('meta_messaging.instagram_profile_url')
            ?: ($instagramUsername ? 'https://www.instagram.com/'.ltrim((string) $instagramUsername, '@').'/' : null);

        $whatsAppChannels = $this->whatsAppChannels($service, $whatsAppPhone);

        return [
            ...$whatsAppChannels,
            [
                'id' => 'facebook',
                'name' => 'فيسبوك',
                'display_name' => data_get($meta, 'name') ?: config('meta_messaging.page_name') ?: 'صفحة فيسبوك',
                'identifier' => config('meta_messaging.page_id'),
                'url' => data_get($meta, 'link') ?: config('meta_messaging.page_url') ?: (config('meta_messaging.page_id') ? 'https://www.facebook.com/'.config('meta_messaging.page_id') : null),
                'configured' => filled(config('meta_messaging.page_access_token')) && filled(config('meta_messaging.page_id')),
                'health' => [
                    'token' => filled(config('meta_messaging.page_access_token')),
                    'identity' => filled(config('meta_messaging.page_id')),
                    'webhook' => filled(config('meta_messaging.verify_token')),
                    'profile' => filled(data_get($meta, 'id')),
                ],
                'details' => [
                    'page_id' => config('meta_messaging.page_id'),
                ],
            ],
            [
                'id' => 'instagram',
                'name' => 'إنستغرام',
                'display_name' => $instagramUsername ? '@'.ltrim((string) $instagramUsername, '@') : (data_get($instagram, 'name') ?: 'حساب إنستغرام'),
                'identifier' => config('meta_messaging.instagram_business_account_id'),
                'url' => $instagramUrl,
                'configured' => filled(config('meta_messaging.page_access_token')) && filled(config('meta_messaging.instagram_business_account_id')),
                'health' => [
                    'token' => filled(config('meta_messaging.page_access_token')),
                    'identity' => filled(config('meta_messaging.instagram_business_account_id')),
                    'webhook' => filled(config('meta_messaging.verify_token')),
                    'profile' => filled(data_get($instagram, 'id')),
                ],
                'details' => [
                    'instagram_business_account_id' => config('meta_messaging.instagram_business_account_id'),
                ],
            ],
        ];
    }

    private function whatsAppChannels(WhatsAppCloudApiService $service, ?string $fallbackPhone): array
    {
        if (\Illuminate\Support\Facades\Schema::hasTable('whatsapp_accounts')) {
            $accounts = WhatsAppAccount::query()->orderBy('sort_order')->orderBy('id')->get();
            if ($accounts->isNotEmpty()) {
                return $accounts->map(fn (WhatsAppAccount $account) => [
                    'id' => 'whatsapp:'.$account->id,
                    'name' => 'واتساب',
                    'display_name' => $account->name,
                    'identifier' => $account->display_phone_number,
                    'url' => $account->display_phone_number ? 'https://wa.me/'.$account->display_phone_number : null,
                    'configured' => $account->is_active && $account->is_verified
                        && filled($account->accessToken())
                        && filled($account->phone_number_id),
                    'health' => [
                        'token' => filled($account->accessToken()),
                        'identity' => filled($account->phone_number_id) && filled($account->waba_id),
                        'webhook' => filled(config('whatsapp.verify_token')),
                        'public_url' => filled(config('app.url')),
                        'catalog' => filled($account->catalog_id),
                    ],
                    'details' => [
                        'account_id' => $account->id,
                        'phone_number_id' => $this->mask($account->phone_number_id),
                        'business_account_id' => $this->mask($account->waba_id),
                        'catalog_id' => $this->mask($account->catalog_id),
                    ],
                ])->all();
            }
        }

        return [[
            'id' => 'whatsapp',
            'name' => 'واتساب',
            'display_name' => $fallbackPhone ? '+'.$fallbackPhone : 'واتساب دكتور بايك',
            'identifier' => $fallbackPhone,
            'url' => $fallbackPhone ? 'https://wa.me/'.$fallbackPhone : null,
            'configured' => filled(config('whatsapp.access_token')) && filled(config('whatsapp.phone_number_id')),
            'health' => [
                'token' => filled(config('whatsapp.access_token')),
                'identity' => filled(config('whatsapp.phone_number_id')) && filled(config('whatsapp.business_account_id')),
                'webhook' => filled(config('whatsapp.verify_token')),
                'public_url' => filled(config('app.url')),
            ],
            'details' => [
                'phone_number_id' => $this->mask(config('whatsapp.phone_number_id')),
                'business_account_id' => $this->mask(config('whatsapp.business_account_id')),
            ],
        ]];
    }

    private function metaPageProfile(): array
    {
        if (blank(config('meta_messaging.page_access_token')) || blank(config('meta_messaging.page_id'))) {
            return [];
        }

        try {
            $response = Http::withToken(config('meta_messaging.page_access_token'))
                ->acceptJson()
                ->timeout(config('meta_messaging.timeout', 20))
                ->get(sprintf(
                    'https://graph.facebook.com/%s/%s',
                    trim(config('meta_messaging.api_version'), '/'),
                    config('meta_messaging.page_id')
                ), [
                    'fields' => 'id,name,link,instagram_business_account{id,username,name,profile_picture_url}',
                ]);

            return $response->successful() ? ($response->json() ?: []) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function mask(?string $value): ?string
    {
        if (! $value) return null;
        return strlen($value) <= 6 ? str_repeat('*', strlen($value)) : substr($value, 0, 3).str_repeat('*', max(strlen($value) - 6, 3)).substr($value, -3);
    }

    private function metaAppStatus(): array
    {
        $published = (bool) config('meta_messaging.app_published', false);
        $mode = (string) config('meta_messaging.app_mode', $published ? 'live' : 'development');

        return [
            'published' => $published,
            'mode' => $mode,
            'message' => $published
                ? 'تطبيق Meta منشور ويستقبل رسائل الحسابات الحقيقية حسب الصلاحيات.'
                : 'تطبيق Meta غير منشور. الرسائل الحقيقية قد تصل فقط من admins/developers/testers إلى أن يتم نشر التطبيق.',
        ];
    }
}
