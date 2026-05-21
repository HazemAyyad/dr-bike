<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\EmployeeDetail;
use Illuminate\Http\Request;

class AdminImpersonationController extends Controller
{
    /**
     * Issue an employee session token for admin support / preview (no password).
     */
    public function impersonate(Request $request, int $employeeId)
    {
        try {
            $admin = $request->user();
            if (! $admin || $admin->type !== 'admin') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized. Admins only.',
                ], 200);
            }

            $employee = EmployeeDetail::with(['user'])->find($employeeId);

            if (! $employee || ! $employee->user) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.employee_not_found'),
                ], 200);
            }

            $user = $employee->user;
            if ($user->type !== 'employee') {
                return response()->json([
                    'status' => 'error',
                    'message' => __('messages.invalid_credentials'),
                ], 200);
            }

            $token = $user->createToken(
                'impersonation-'.$admin->id,
                ['*'],
                now()->addDay()
            )->plainTextToken;

            $employee->setAttribute('employee_img', $this->formatEmployeeImagePath($employee->employee_img));
            $employee->setAttribute('document_img', $this->formatDocumentImagePath($employee->document_img));

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
                \Illuminate\Support\Facades\Log::warning('impersonate permissions: '.$e->getMessage());
            }

            $employee->unsetRelation('permissions');
            $user->setRelation('employee', $employee);

            return response()->json([
                'status' => 'success',
                'user' => $user,
                'token' => $token,
                'employee_permissions' => $employeePermissions,
                'impersonation' => [
                    'active' => true,
                    'admin_id' => $admin->id,
                    'admin_name' => $admin->name,
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
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
