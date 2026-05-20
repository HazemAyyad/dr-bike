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

            $employee = EmployeeDetail::with(['user', 'permissions.permission'])
                ->find($employeeId);

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

            $employee->employee_img = $employee->employee_img
                ? 'public/EmployeeImages/'.$employee->employee_img[0]
                : null;
            $employee->document_img = $employee->document_img
                ? 'public/EmployeeDocumetImages/'.$employee->document_img[0]
                : null;

            $user->setRelation('employee', $employee);

            $employeePermissions = $employee->permissions->map(function ($permission) {
                return [
                    'permission_id' => $permission->permission->id,
                    'permission_name' => $permission->permission->name,
                    'permission_name_en' => $permission->permission->name_en,
                ];
            });

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
}
