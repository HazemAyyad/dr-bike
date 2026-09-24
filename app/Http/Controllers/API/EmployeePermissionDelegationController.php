<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\EmployeeDetail;
use App\Services\EmployeePermissionDelegationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EmployeePermissionDelegationController extends Controller
{
    public function context(
        Request $request,
        EmployeeDetail $employee,
        EmployeePermissionDelegationService $service
    ) {
        try {
            $employee->loadMissing('user');

            return response()->json([
                'status' => 'success',
                'data' => $service->context($request->user(), $employee),
            ]);
        } catch (AuthorizationException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 403);
        }
    }

    public function sync(
        Request $request,
        EmployeeDetail $employee,
        EmployeePermissionDelegationService $service
    ) {
        try {
            $data = $request->validate([
                'permission_ids' => ['present', 'array'],
                'permission_ids.*' => ['integer', 'exists:permissions,id'],
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'تم تحديث صلاحيات الموظف بنجاح.',
                'data' => $service->sync(
                    $request->user(),
                    $employee,
                    $data['permission_ids'],
                    $request->ip()
                ),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 422);
        } catch (AuthorizationException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 403);
        }
    }

    public function setDelegation(
        Request $request,
        EmployeeDetail $employee,
        EmployeePermissionDelegationService $service
    ) {
        $data = $request->validate([
            'can_delegate_permissions' => ['required', 'boolean'],
        ]);
        $service->setDelegation(
            $request->user(),
            $employee,
            (bool) $data['can_delegate_permissions'],
            $request->ip()
        );

        return response()->json([
            'status' => 'success',
            'message' => 'تم تحديث صلاحية تفويض الصلاحيات.',
            'can_delegate_permissions' => (bool) $data['can_delegate_permissions'],
        ]);
    }
}
