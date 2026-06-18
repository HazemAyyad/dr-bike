<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\EmployeeAttendanceOvertimeService;
use Illuminate\Http\Request;

class EmployeeAttendanceOvertimeController extends Controller
{
    public function index(Request $request, EmployeeAttendanceOvertimeService $service)
    {
        try {
            $status = (string) $request->input('status', 'pending');

            $requests = $service->listForAdmin($status)->map(
                fn ($row) => $service->toApiArray($row)
            )->values();

            return response()->json([
                'status' => 'success',
                'requests' => $requests,
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function approve(Request $request, int $requestId, EmployeeAttendanceOvertimeService $service)
    {
        try {
            $data = $request->validate([
                'approved_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
                'admin_note' => ['nullable', 'string', 'max:500'],
            ]);

            $admin = $request->user();
            $row = $service->approve(
                $requestId,
                (int) $admin->id,
                isset($data['approved_minutes']) ? (int) $data['approved_minutes'] : null,
                $data['admin_note'] ?? null
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.attendance_overtime_approved'),
                'request' => $service->toApiArray($row),
            ], 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function reject(Request $request, int $requestId, EmployeeAttendanceOvertimeService $service)
    {
        try {
            $data = $request->validate([
                'admin_note' => ['nullable', 'string', 'max:500'],
            ]);

            $admin = $request->user();
            $row = $service->reject(
                $requestId,
                (int) $admin->id,
                $data['admin_note'] ?? null
            );

            return response()->json([
                'status' => 'success',
                'message' => __('messages.attendance_overtime_rejected'),
                'request' => $service->toApiArray($row),
            ], 200);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }
}
