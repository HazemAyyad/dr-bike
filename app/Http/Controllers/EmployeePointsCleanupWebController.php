<?php

namespace App\Http\Controllers;

use App\Models\EmployeeDetail;
use App\Services\EmployeePointsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmployeePointsCleanupWebController extends Controller
{
    public function index(Request $request, EmployeePointsService $pointsService): View
    {
        $search = trim((string) $request->query('search', ''));
        $employees = EmployeeDetail::query()
            ->with('user:id,name')
            ->withCount([
                'pointsLogs',
                'pointsLogs as evidence_count' => fn ($query) => $query->whereNotNull('image_path'),
            ])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('id', $search)
                        ->orWhere('job_title', 'like', "%{$search}%")
                        ->orWhereHas('user', fn ($user) => $user->where('name', 'like', "%{$search}%"));
                });
            })
            ->orderBy('id')
            ->get(['id', 'user_id', 'job_title', 'is_suspended']);

        $balances = $pointsService->getTotalNetPointsMany($employees->pluck('id')->all());
        $employees = $employees
            ->map(function (EmployeeDetail $employee) use ($balances) {
                $employee->current_points = (int) ($balances[$employee->id] ?? 0);

                return $employee;
            })
            ->sortBy([
                fn (EmployeeDetail $a, EmployeeDetail $b) => ($a->points_logs_count === 0 ? 1 : 0)
                    <=> ($b->points_logs_count === 0 ? 1 : 0),
                fn (EmployeeDetail $a, EmployeeDetail $b) => strnatcasecmp(
                    (string) ($a->user?->name ?? ''),
                    (string) ($b->user?->name ?? '')
                ),
            ])
            ->values();

        return view('employee-points-cleanup', [
            'employees' => $employees,
            'search' => $search,
            'stats' => [
                'employees' => $employees->count(),
                'with_history' => $employees->where('points_logs_count', '>', 0)->count(),
                'logs' => $employees->sum('points_logs_count'),
                'evidence' => $employees->sum('evidence_count'),
            ],
        ]);
    }

    public function deleteEmployee(
        Request $request,
        EmployeeDetail $employee,
        EmployeePointsService $pointsService
    ): RedirectResponse {
        $request->validate([
            'confirmed' => ['required', 'accepted'],
        ]);

        $result = $pointsService->deleteHistory(
            (int) $employee->id,
            $this->auditNote($request, 'حذف سجل نقاط موظف واحد')
        );

        $employeeName = (string) ($employee->user?->name ?? "موظف #{$employee->id}");
        if ($result['logs_deleted'] === 0) {
            return back()->with('flash', "لا توجد حركات نقاط محفوظة للموظف {$employeeName}.");
        }

        return back()->with('flash', $this->successMessage($result, "الموظف {$employeeName}"));
    }

    public function deleteAll(Request $request, EmployeePointsService $pointsService): RedirectResponse
    {
        $data = $request->validate([
            'confirmation' => ['required', 'string', 'max:100'],
        ]);

        if (trim((string) $data['confirmation']) !== 'حذف جميع النقاط نهائيا') {
            return back()->withErrors([
                'confirmation' => 'اكتب عبارة «حذف جميع النقاط نهائيا» كما هي لتأكيد العملية.',
            ])->withInput();
        }

        $employeeIds = EmployeeDetail::query()->orderBy('id')->pluck('id')->all();
        $result = $pointsService->deleteManyHistories(
            $employeeIds,
            $this->auditNote($request, 'حذف سجل نقاط جميع الموظفين')
        );

        if ($result['logs_deleted'] === 0) {
            return back()->with('flash', 'لا توجد حركات نقاط محفوظة لأي موظف.');
        }

        return back()->with('flash', $this->successMessage($result, 'جميع الموظفين'));
    }

    /**
     * @param  array<string, int>  $result
     */
    private function successMessage(array $result, string $scope): string
    {
        $message = 'تم الحذف النهائي لـ'.number_format($result['logs_deleted'])." حركة نقاط تخص {$scope}،"
            .' مع '.number_format($result['evidence_files_deleted']).' ملف إثبات.';

        if ($result['evidence_files_failed'] > 0) {
            $message .= ' تعذر حذف '.number_format($result['evidence_files_failed']).' ملف من التخزين؛ راجع سجل Laravel.';
        }

        return $message;
    }

    private function auditNote(Request $request, string $action): string
    {
        return "{$action} من صفحة مركز الأمان. IP: ".((string) $request->ip());
    }
}
