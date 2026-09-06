<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeePointCategoryResource;
use App\Http\Resources\EmployeePointsLogResource;
use App\Models\EmployeeDetail;
use App\Models\EmployeePointCategory;
use App\Models\EmployeePointsLog;
use App\Services\EmployeePointsService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeePointsController extends Controller
{
    public function __construct(private readonly EmployeePointsService $pointsService)
    {
    }

    public function add(Request $request, int $employee)
    {
        return $this->mutate($request, $employee, EmployeePointsLog::OPERATION_ADD);
    }

    public function deduct(Request $request, int $employee)
    {
        return $this->mutate($request, $employee, EmployeePointsLog::OPERATION_DEDUCT);
    }

    private function mutate(Request $request, int $employee, string $expectedOperation)
    {
        $storedImagePath = null;
        try {
            $data = $this->validateMutation($request);
            $employeeModel = EmployeeDetail::findOrFail($employee);

            if ($request->hasFile('image')) {
                $storedImagePath = $request->file('image')->store(
                    'employee-points/'.$employeeModel->id,
                    'public',
                );
                $data['image_path'] = $storedImagePath;
            }

            $category = null;
            if (! empty($data['category_id'])) {
                /** @var EmployeePointCategory $category */
                $category = EmployeePointCategory::findOrFail((int) $data['category_id']);

                if (! $category->is_active) {
                    return response()->json([
                        'status' => 'error',
                        'message' => __('messages.category_inactive'),
                    ], 200);
                }

                if ($category->operation_type !== $expectedOperation) {
                    return response()->json([
                        'status' => 'error',
                        'message' => __('messages.category_mismatch'),
                    ], 200);
                }

                $log = $this->pointsService->applyCategoryMutation(
                    $employeeModel->id,
                    $category,
                    $data,
                );
            } else {
                $log = $expectedOperation === EmployeePointsLog::OPERATION_ADD
                    ? $this->pointsService->addPoints($employeeModel->id, $data)
                    : $this->pointsService->deductPoints($employeeModel->id, $data);
            }

            $log->loadMissing('creator', 'categoryRelation');

            return response()->json([
                'status' => 'success',
                'message' => __('messages.points_updated'),
                'log' => new EmployeePointsLogResource($log),
            ]);
        } catch (ValidationException $e) {
            if ($storedImagePath !== null) {
                Storage::disk('public')->delete($storedImagePath);
            }
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (ModelNotFoundException $e) {
            if ($storedImagePath !== null) {
                Storage::disk('public')->delete($storedImagePath);
            }
            return response()->json([
                'status' => 'error',
                'message' => __('messages.employee_not_found'),
            ], 200);
        } catch (\Throwable $e) {
            if ($storedImagePath !== null) {
                Storage::disk('public')->delete($storedImagePath);
            }
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function logs(Request $request, int $employee)
    {
        try {
            $employeeModel = EmployeeDetail::findOrFail($employee);

            $validated = $request->validate([
                'month' => ['nullable', 'integer', 'between:1,12'],
                'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
                'category' => ['nullable', 'string', 'max:64'],
                'category_id' => ['nullable', 'integer', 'min:1'],
                'operation_type' => ['nullable', Rule::in([EmployeePointsLog::OPERATION_ADD, EmployeePointsLog::OPERATION_DEDUCT])],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            ]);

            /** @phpstan-ignore-next-line */
            $query = EmployeePointsLog::query()
                ->forEmployee($employeeModel->id)
                ->with(['creator:id,name', 'categoryRelation'])
                ->orderByDesc('points_date')
                ->orderByDesc('id');

            if (! empty($validated['year']) && ! empty($validated['month'])) {
                $query->inMonth((int) $validated['year'], (int) $validated['month']);
            } elseif (! empty($validated['year'])) {
                $query->whereYear('points_date', (int) $validated['year']);
            } elseif (! empty($validated['month'])) {
                $query->whereMonth('points_date', (int) $validated['month']);
            }

            if (! empty($validated['category'])) {
                $query->where('category', $validated['category']);
            }
            if (! empty($validated['category_id'])) {
                $query->where('category_id', (int) $validated['category_id']);
            }
            if (! empty($validated['operation_type'])) {
                $query->where('operation_type', $validated['operation_type']);
            }

            $perPage = (int) ($validated['per_page'] ?? 50);
            $logs = $query->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'logs' => EmployeePointsLogResource::collection($logs->items()),
                'meta' => [
                    'current_page' => $logs->currentPage(),
                    'last_page' => $logs->lastPage(),
                    'per_page' => $logs->perPage(),
                    'total' => $logs->total(),
                ],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.employee_not_found'),
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function monthlySummary(Request $request, int $employee)
    {
        try {
            $employeeModel = EmployeeDetail::findOrFail($employee);

            $validated = $request->validate([
                'month' => ['nullable', 'integer', 'between:1,12'],
                'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            ]);

            $now = Carbon::now();
            $month = (int) ($validated['month'] ?? $now->month);
            $year = (int) ($validated['year'] ?? $now->year);

            $summary = $this->pointsService->getMonthlySummary($employeeModel->id, $year, $month);

            return response()->json([
                'status' => 'success',
                'month' => $month,
                'year' => $year,
                'summary' => [
                    'earned_points' => (int) $summary['earned_points'],
                    'deducted_points' => (int) $summary['deducted_points'],
                    'net_points' => (int) $summary['net_points'],
                    'reward_amount' => number_format((float) $summary['reward_amount'], 2, '.', ''),
                    'matched_rule_id' => $summary['matched_rule_id'],
                    'reward_rule_id' => $summary['reward_rule_id'],
                    'reward_status_label' => $summary['reward_status_label'],
                    'reward_status_color' => $summary['reward_status_color'],
                ],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.employee_not_found'),
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function categories()
    {
        /** @phpstan-ignore-next-line */
        $dbCategories = EmployeePointCategory::query()->active()->ordered()->get();

        return response()->json([
            'status' => 'success',
            'categories' => [
                'positive' => $this->pointsService->positiveCategories(),
                'negative' => $this->pointsService->negativeCategories(),
            ],
            'configurable_categories' => EmployeePointCategoryResource::collection($dbCategories),
        ]);
    }

    /**
     * Global employees points list (no employee param). Shows the current
     * month points + reward status for every employee, with optional search.
     */
    public function globalEmployees(Request $request)
    {
        try {
            $validated = $request->validate([
                'month' => ['nullable', 'integer', 'between:1,12'],
                'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
                'search' => ['nullable', 'string', 'max:120'],
            ]);

            $now = Carbon::now();
            $month = (int) ($validated['month'] ?? $now->month);
            $year = (int) ($validated['year'] ?? $now->year);
            $search = isset($validated['search']) ? trim((string) $validated['search']) : '';

            /** @phpstan-ignore-next-line */
            $query = EmployeeDetail::query()
                ->with('user:id,name')
                ->select(['id', 'user_id', 'employee_img', 'hour_work_price']);

            if ($search !== '') {
                $query->whereHas('user', function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%");
                });
            }

            $employees = $query->orderBy('id')->get();
            $summaries = $this->pointsService->getMonthlySummaryMany($employees->pluck('id')->all(), $year, $month);

            $rows = $employees->map(function ($emp) use ($summaries) {
                $summary = $summaries[$emp->id] ?? [
                    'earned_points' => 0,
                    'deducted_points' => 0,
                    'net_points' => 0,
                    'reward_amount' => 0.0,
                    'reward_rule_id' => null,
                    'reward_status_label' => null,
                    'reward_status_color' => null,
                ];

                return [
                    'employee_id' => (int) $emp->id,
                    'employee_name' => $emp->user?->name,
                    'employee_img' => $emp->employee_img ? 'public/EmployeeImages/' . $emp->employee_img[0] : null,
                    'earned_points' => (int) $summary['earned_points'],
                    'deducted_points' => (int) $summary['deducted_points'],
                    'net_points' => (int) $summary['net_points'],
                    'reward_amount' => number_format((float) $summary['reward_amount'], 2, '.', ''),
                    'reward_rule_id' => $summary['reward_rule_id'],
                    'reward_status_label' => $summary['reward_status_label'],
                    'reward_status_color' => $summary['reward_status_color'],
                ];
            })->values();

            return response()->json([
                'status' => 'success',
                'month' => $month,
                'year' => $year,
                'employees' => $rows,
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    /**
     * Aggregate report for points and rewards across employees, with optional
     * embedded logs and per-row filters.
     */
    public function globalReport(Request $request)
    {
        try {
            $validated = $request->validate([
                'month' => ['nullable', 'integer', 'between:1,12'],
                'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
                'employee_ids' => ['nullable', 'array'],
                'employee_ids.*' => ['integer', 'min:1'],
                'operation_type' => ['nullable', Rule::in([EmployeePointsLog::OPERATION_ADD, EmployeePointsLog::OPERATION_DEDUCT])],
                'category_id' => ['nullable', 'integer', 'min:1'],
                'include_logs' => ['nullable', 'boolean'],
            ]);

            $now = Carbon::now();
            $month = (int) ($validated['month'] ?? $now->month);
            $year = (int) ($validated['year'] ?? $now->year);
            $start = Carbon::create($year, $month, 1)->startOfMonth()->toDateString();
            $end = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();

            $employeeIds = $validated['employee_ids'] ?? [];

            /** @phpstan-ignore-next-line */
            $employeesQuery = EmployeeDetail::query()->with('user:id,name')->select(['id', 'user_id']);
            if (! empty($employeeIds)) {
                $employeesQuery->whereIn('id', $employeeIds);
            }
            $employees = $employeesQuery->orderBy('id')->get();

            // Build per-employee aggregates respecting filters.
            /** @phpstan-ignore-next-line */
            $aggQuery = EmployeePointsLog::query()
                ->whereBetween('points_date', [$start, $end])
                ->whereIn('employee_id', $employees->pluck('id')->all());

            if (! empty($validated['operation_type'])) {
                $aggQuery->where('operation_type', $validated['operation_type']);
            }
            if (! empty($validated['category_id'])) {
                $aggQuery->where('category_id', (int) $validated['category_id']);
            }

            $aggregates = $aggQuery
                ->selectRaw('employee_id, operation_type, COALESCE(SUM(points), 0) as total_points')
                ->groupBy('employee_id', 'operation_type')
                ->get();

            $byEmp = [];
            foreach ($aggregates as $row) {
                $eid = (int) $row->employee_id;
                $byEmp[$eid] ??= ['earned_points' => 0, 'deducted_points' => 0];
                if ($row->operation_type === EmployeePointsLog::OPERATION_ADD) {
                    $byEmp[$eid]['earned_points'] += (int) $row->total_points;
                } elseif ($row->operation_type === EmployeePointsLog::OPERATION_DEDUCT) {
                    $byEmp[$eid]['deducted_points'] += (int) $row->total_points;
                }
            }

            $includeLogs = (bool) ($validated['include_logs'] ?? false);

            $employeeRows = [];
            $totalEarned = 0;
            $totalDeducted = 0;
            $totalReward = 0.0;
            $totalNet = 0;

            foreach ($employees as $emp) {
                $earned = (int) ($byEmp[$emp->id]['earned_points'] ?? 0);
                $deducted = (int) ($byEmp[$emp->id]['deducted_points'] ?? 0);
                $net = $earned - $deducted;
                $rule = $this->pointsService->matchRewardRule($net);

                $row = [
                    'employee_id' => (int) $emp->id,
                    'employee_name' => $emp->user?->name,
                    'earned_points' => $earned,
                    'deducted_points' => $deducted,
                    'net_points' => $net,
                    'reward_amount' => number_format((float) ($rule?->reward_amount ?? 0), 2, '.', ''),
                    'reward_rule_id' => $rule?->id,
                    'reward_status_label' => $rule?->status_label ?? $this->pointsServiceFallbackLabel($net, $rule),
                    'reward_status_color' => $rule?->status_color ?? $this->pointsServiceFallbackColor($net, $rule),
                ];

                if ($includeLogs) {
                    /** @phpstan-ignore-next-line */
                    $logsQ = EmployeePointsLog::query()
                        ->forEmployee($emp->id)
                        ->whereBetween('points_date', [$start, $end])
                        ->with(['creator:id,name', 'categoryRelation'])
                        ->orderByDesc('points_date')
                        ->orderByDesc('id');
                    if (! empty($validated['operation_type'])) {
                        $logsQ->where('operation_type', $validated['operation_type']);
                    }
                    if (! empty($validated['category_id'])) {
                        $logsQ->where('category_id', (int) $validated['category_id']);
                    }
                    $row['logs'] = EmployeePointsLogResource::collection($logsQ->get())->toArray($request);
                }

                $employeeRows[] = $row;

                $totalEarned += $earned;
                $totalDeducted += $deducted;
                $totalNet += $net;
                $totalReward += (float) ($rule?->reward_amount ?? 0);
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'month' => $month,
                    'year' => $year,
                    'employees' => $employeeRows,
                    'totals' => [
                        'earned_points' => $totalEarned,
                        'deducted_points' => $totalDeducted,
                        'net_points' => $totalNet,
                        'reward_amount' => number_format($totalReward, 2, '.', ''),
                    ],
                ],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    private function pointsServiceFallbackLabel(int $net, $rule): string
    {
        if ($net < 0) {
            return __('messages.reward_status_negative');
        }
        if ($net === 0) {
            return __('messages.reward_status_none');
        }
        if ($rule === null) {
            return __('messages.reward_status_no_match');
        }
        if ($rule->max_points === null) {
            return __('messages.reward_status_top');
        }

        return __('messages.reward_status_matched');
    }

    private function pointsServiceFallbackColor(int $net, $rule): string
    {
        if ($net < 0) {
            return '#DC2626';
        }
        if ($net === 0) {
            return '#9CA3AF';
        }
        if ($rule === null) {
            return '#F59E0B';
        }
        if ($rule->max_points === null) {
            return '#16A34A';
        }

        return '#2563EB';
    }

    /**
     * @return array<string, mixed>
     */
    private function validateMutation(Request $request): array
    {
        // When no category_id is sent we fall back to legacy validation that
        // requires `points` and a free-text `category`. When a category_id is
        // present points/category become optional because they come from the
        // category itself (with optional override).
        $hasCategoryId = $request->filled('category_id');

        return $request->validate([
            'points' => [$hasCategoryId ? 'nullable' : 'required', 'integer', 'min:1'],
            'category' => [$hasCategoryId ? 'nullable' : 'required', 'string', 'max:64'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'points_date' => ['nullable', 'date'],
            'source' => ['nullable', Rule::in(config('employee_points.sources', []))],
        ]);
    }
}
