<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppSetting;
use App\Models\EmployeeDetail;
use App\Models\EmployeePermission;
use App\Models\Permission;
use App\Services\WhatsApp\WhatsAppCloudApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WhatsAppSettingsController extends Controller
{
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
            'employee_ids' => 'present|array',
            'employee_ids.*' => 'integer|exists:employee_details,id',
        ]);
        $permission = Permission::query()
            ->where('name_en', 'Messages Section')
            ->firstOrFail();
        $employeeIds = collect($data['employee_ids'])->map(fn ($id) => (int) $id)->unique()->values();

        DB::transaction(function () use ($permission, $employeeIds) {
            EmployeePermission::query()
                ->where('permission_id', $permission->id)
                ->delete();
            foreach ($employeeIds as $employeeId) {
                EmployeePermission::query()->create([
                    'employee_id' => $employeeId,
                    'permission_id' => $permission->id,
                ]);
            }
        });

        return response()->json([
            'status' => 'success',
            'employees' => $this->employeesWithAccess(),
        ]);
    }

    private function employeesWithAccess(): array
    {
        $permissionId = Permission::query()
            ->where('name_en', 'Messages Section')
            ->value('id');

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
                'has_whatsapp_access' => $permissionId
                    ? $employee->permissions()->where('permission_id', $permissionId)->exists()
                    : false,
            ])
            ->all();
    }

    private function mask(?string $value): ?string
    {
        if (! $value) return null;
        return strlen($value) <= 6 ? str_repeat('*', strlen($value)) : substr($value, 0, 3).str_repeat('*', max(strlen($value) - 6, 3)).substr($value, -3);
    }
}
