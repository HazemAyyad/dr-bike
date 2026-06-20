<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\ImpersonationService as EmployeeImpersonationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EmployeeImpersonationController extends Controller
{
    public function __construct(
        private readonly EmployeeImpersonationService $impersonationService
    ) {}

    /**
     * Employee with "Employee Impersonation" permission enters another employee account.
     */
    public function impersonate(Request $request, int $employeeId)
    {
        try {
            $user = $request->user();
            if (! $user || $user->type !== 'employee') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized.',
                ], 200);
            }

            $result = $this->impersonationService->impersonateEmployee($user, $employeeId);

            return response()->json($result, 200);
        } catch (\Throwable $e) {
            Log::error('employee impersonate failed', [
                'employee_id' => $employeeId,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }
}
