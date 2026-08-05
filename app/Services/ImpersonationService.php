<?php

namespace App\Services;

use App\Models\EmployeeDetail;
use App\Models\User;
use App\Services\EmployeePointsService;
use Illuminate\Support\Facades\Log;

class ImpersonationService
{
    /**
     * @return array{status: string, user?: array, token?: string, employee_permissions?: mixed, impersonation?: array, message?: string}
     */
    public function impersonateEmployee(User $impersonator, int $employeeId): array
    {
        if ($impersonator->type === 'employee') {
            $impersonator->loadMissing('employee');
            if ($impersonator->employee && (int) $impersonator->employee->id === $employeeId) {
                return [
                    'status' => 'error',
                    'message' => __('messages.cannot_impersonate_self'),
                ];
            }
        }

        $employee = EmployeeDetail::with(['user'])->find($employeeId);

        if (! $employee || ! $employee->user) {
            return [
                'status' => 'error',
                'message' => __('messages.employee_not_found'),
            ];
        }

        $user = $employee->user;
        if ($user->type !== 'employee') {
            return [
                'status' => 'error',
                'message' => __('messages.invalid_credentials'),
            ];
        }

        $token = $user->createToken(
            'impersonation-'.$impersonator->id,
            ['*'],
            now()->addDay()
        )->plainTextToken;

        $employeePermissions = collect();
        try {
            $employee->load(['permissions.permission']);
            $employeePermissions = $employee->permissions
                ->filter(fn ($p) => $p->permission !== null)
                ->map(fn ($permission) => [
                    'permission_id' => $permission->permission->id,
                    'permission_name' => $permission->permission->name,
                    'permission_name_en' => $permission->permission->name_en,
                ])
                ->values();
        } catch (\Throwable $e) {
            Log::warning('impersonate permissions: '.$e->getMessage());
        }

        return [
            'status' => 'success',
            'user' => $this->buildUserPayload($user, $employee),
            'token' => $token,
            'employee_permissions' => $employeePermissions,
            'impersonation' => [
                'active' => true,
                'impersonator_id' => $impersonator->id,
                'impersonator_name' => $impersonator->name,
                'impersonator_type' => $impersonator->type,
                'admin_id' => $impersonator->type === 'admin' ? $impersonator->id : null,
                'admin_name' => $impersonator->type === 'admin' ? $impersonator->name : null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildUserPayload(User $user, EmployeeDetail $employee): array
    {
        $empImg = $this->formatEmployeeImagePath($employee->employee_img);
        $docImg = $this->formatDocumentImagePath($employee->document_img);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at,
            'phone' => $user->phone,
            'sub_phone' => $user->sub_phone,
            'city' => $user->city,
            'address' => $user->address,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
            'type' => $user->type,
            'fcm_token' => $user->fcm_token,
            'employee' => [
                'id' => $employee->id,
                'user_id' => (string) $employee->user_id,
                'points' => (string) app(EmployeePointsService::class)->getTotalNetPoints((int) $employee->id),
                'hour_work_price' => (string) ($employee->hour_work_price ?? '0'),
                'overtime_work_price' => (string) ($employee->overtime_work_price ?? '0'),
                'number_of_work_hours' => (string) ($employee->number_of_work_hours ?? '0'),
                'start_work_time' => (string) ($employee->start_work_time ?? ''),
                'end_work_time' => (string) ($employee->end_work_time ?? ''),
                'job_title' => $employee->job_title,
                'salary' => (string) ($employee->salary ?? '0'),
                'debts' => (string) ($employee->debts ?? '0'),
                'created_at' => $employee->created_at,
                'updated_at' => $employee->updated_at,
                'work_time' => $employee->work_time,
                'employee_img' => $empImg ?? '',
                'document_img' => $docImg ?? '',
                'total_work_hours' => (string) ($employee->total_work_hours ?? '0'),
            ],
        ];
    }

    private function formatEmployeeImagePath(mixed $img): ?string
    {
        if (empty($img)) {
            return null;
        }
        if (is_array($img)) {
            return 'public/EmployeeImages/'.($img[0] ?? '');
        }

        return str_starts_with((string) $img, 'public/')
            ? (string) $img
            : 'public/EmployeeImages/'.(string) $img;
    }

    private function formatDocumentImagePath(mixed $img): ?string
    {
        if (empty($img)) {
            return null;
        }
        if (is_array($img)) {
            return 'public/EmployeeDocumetImages/'.($img[0] ?? '');
        }

        return str_starts_with((string) $img, 'public/')
            ? (string) $img
            : 'public/EmployeeDocumetImages/'.(string) $img;
    }
}
