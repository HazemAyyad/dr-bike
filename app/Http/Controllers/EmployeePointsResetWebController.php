<?php

namespace App\Http\Controllers;

use App\Models\EmployeeDetail;
use App\Services\EmployeePointsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmployeePointsResetWebController extends Controller
{
    public function index(Request $request, EmployeePointsService $pointsService): View
    {
        $search = trim((string) $request->query('search', ''));
        $employees = EmployeeDetail::query()
            ->with('user:id,name')
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
                fn (EmployeeDetail $a, EmployeeDetail $b) => ($a->current_points === 0 ? 1 : 0)
                    <=> ($b->current_points === 0 ? 1 : 0),
                fn (EmployeeDetail $a, EmployeeDetail $b) => strnatcasecmp(
                    (string) ($a->user?->name ?? ''),
                    (string) ($b->user?->name ?? '')
                ),
            ])
            ->values();

        return view('employee-points-reset', [
            'employees' => $employees,
            'search' => $search,
            'stats' => [
                'employees' => $employees->count(),
                'non_zero' => $employees->where('current_points', '!=', 0)->count(),
                'positive' => $employees->where('current_points', '>', 0)->count(),
                'negative' => $employees->where('current_points', '<', 0)->count(),
            ],
        ]);
    }

    public function resetEmployee(
        Request $request,
        EmployeeDetail $employee,
        EmployeePointsService $pointsService
    ): RedirectResponse {
        $request->validate([
            'confirmed' => ['required', 'accepted'],
        ]);

        $log = $pointsService->resetToZero(
            (int) $employee->id,
            $this->auditNote($request, 'تصفير موظف واحد')
        );

        $employeeName = (string) ($employee->user?->name ?? "موظف #{$employee->id}");
        if ($log === null) {
            return back()->with('flash', "رصيد {$employeeName} يساوي صفر أصلًا، ولم تُنشأ أي حركة.");
        }

        return back()->with(
            'flash',
            "تم تصفير رصيد {$employeeName} بتسوية موثقة مقدارها ".number_format((int) $log->points).' نقطة.'
        );
    }

    public function resetAll(Request $request, EmployeePointsService $pointsService): RedirectResponse
    {
        $data = $request->validate([
            'confirmation' => ['required', 'string', 'max:100'],
        ]);

        if (trim((string) $data['confirmation']) !== 'تصفير الجميع') {
            return back()->withErrors([
                'confirmation' => 'اكتب عبارة «تصفير الجميع» كما هي لتأكيد العملية.',
            ])->withInput();
        }

        $employeeIds = EmployeeDetail::query()->orderBy('id')->pluck('id')->all();
        $logs = $pointsService->resetManyToZero(
            $employeeIds,
            $this->auditNote($request, 'تصفير جميع الموظفين')
        );
        $adjustedPoints = collect($logs)->sum(fn ($log) => (int) $log->points);

        return back()->with(
            'flash',
            'تم تصفير '.number_format(count($logs)).' موظف/موظفين بحركات تسوية موثقة مجموعها '
                .number_format($adjustedPoints).' نقطة. الموظفون ذوو الرصيد صفر لم يتغيروا.'
        );
    }

    private function auditNote(Request $request, string $action): string
    {
        return "{$action} من صفحة مركز الأمان. IP: ".((string) $request->ip());
    }
}
