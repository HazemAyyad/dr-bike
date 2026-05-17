<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\EmployeeNotification;
use Illuminate\Http\Request;

class EmployeeNotificationCenterController extends Controller
{
    protected function employeeId(Request $request): int
    {
        $employee = $request->user()->employee;

        return (int) $employee->id;
    }

    protected function scopedQuery(Request $request)
    {
        return EmployeeNotification::query()
            ->where('employee_id', $this->employeeId($request))
            ->orderByDesc('id');
    }

    public function index(Request $request)
    {
        try {
            $query = $this->scopedQuery($request);

            if ($request->boolean('unread_only')) {
                $query->where('is_read', false);
            }

            if ($request->filled('type')) {
                $query->where('type', $request->string('type'));
            }

            $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
            $paginator = $query->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'notifications' => $paginator,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function unreadCount(Request $request)
    {
        try {
            $count = $this->scopedQuery($request)->where('is_read', false)->count();

            return response()->json([
                'status' => 'success',
                'unread_count' => $count,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function markRead(Request $request, int $id)
    {
        try {
            $notification = $this->scopedQuery($request)->whereKey($id)->firstOrFail();
            $notification->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

            return response()->json([
                'status' => 'success',
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function markAllRead(Request $request)
    {
        try {
            $this->scopedQuery($request)->where('is_read', false)->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

            return response()->json([
                'status' => 'success',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function destroy(Request $request, int $id)
    {
        try {
            $this->scopedQuery($request)->whereKey($id)->delete();

            return response()->json([
                'status' => 'success',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }
}
