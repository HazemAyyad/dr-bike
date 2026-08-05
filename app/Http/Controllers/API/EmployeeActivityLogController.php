<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\EmployeeActivityLog;
use App\Models\EmployeeDetail;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EmployeeActivityLogController extends Controller
{
    public function index(Request $request, int $employee)
    {
        try {
            EmployeeDetail::query()->findOrFail($employee);

            $data = $request->validate([
                'module' => 'nullable|string|max:60',
                'search' => 'nullable|string|max:255',
                'from_date' => 'nullable|date',
                'to_date' => 'nullable|date',
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:1|max:100',
            ]);

            $query = EmployeeActivityLog::query()
                ->where('employee_id', $employee)
                ->latest();

            if (! empty($data['module']) && $data['module'] !== 'all') {
                $query->where('module', $data['module']);
            }

            if (! empty($data['search'])) {
                $search = trim($data['search']);
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', '%'.$search.'%')
                        ->orWhere('description', 'like', '%'.$search.'%')
                        ->orWhere('action', 'like', '%'.$search.'%');
                });
            }

            if (! empty($data['from_date'])) {
                $query->whereDate('created_at', '>=', $data['from_date']);
            }

            if (! empty($data['to_date'])) {
                $query->whereDate('created_at', '<=', $data['to_date']);
            }

            $perPage = (int) ($data['per_page'] ?? 20);
            $page = $query->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'summary' => [
                    'total_logs' => (clone $query)->count(),
                    'sales_amount' => (float) (clone $query)->where('module', 'sales')->sum('amount'),
                    'debts_amount' => (float) (clone $query)->where('module', 'debts')->sum('amount'),
                    'completed_tasks' => (clone $query)->where('module', 'tasks')->count(),
                ],
                'logs' => collect($page->items())->map(fn (EmployeeActivityLog $log) => $this->payload($log))->values(),
                'pagination' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                ],
                'modules' => ['all', 'sales', 'debts', 'maintenance', 'tasks', 'stock'],
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error'),
            ], 200);
        }
    }

    private function payload(EmployeeActivityLog $log): array
    {
        return [
            'id' => (int) $log->id,
            'employee_id' => (int) $log->employee_id,
            'actor_user_id' => $log->actor_user_id ? (int) $log->actor_user_id : null,
            'module' => (string) $log->module,
            'action' => (string) $log->action,
            'title' => (string) $log->title,
            'description' => (string) ($log->description ?? ''),
            'amount' => $log->amount,
            'metadata' => $log->metadata ?? [],
            'created_at' => optional($log->created_at)->format('Y-m-d H:i:s'),
            'navigation' => $this->navigation($log),
        ];
    }

    private function navigation(EmployeeActivityLog $log): ?array
    {
        if (! $log->subject_type || ! $log->subject_id) {
            return null;
        }

        $label = match ($log->subject_type) {
            'sales_order', 'instant_sale' => 'فتح الفاتورة',
            'debt' => 'فتح الدين',
            'maintenance' => 'فتح الصيانة',
            'employee_task', 'employee_task_occurrence', 'employee_sub_task' => 'فتح المهمة',
            'special_task' => 'فتح المهمة الخاصة',
            default => 'فتح التفاصيل',
        };

        return [
            'type' => $log->subject_type,
            'id' => (int) $log->subject_id,
            'label' => $label,
        ];
    }
}
