<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeePointsLogResource;
use App\Models\EmployeeDetail;
use App\Models\EmployeePointsLog;
use App\Services\EmployeePointsService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeePointsController extends Controller
{
    public function __construct(private readonly EmployeePointsService $pointsService)
    {
    }

    /**
     * @return string[]
     */
    private function knownCategories(): array
    {
        return $this->pointsService->allCategories();
    }

    public function add(Request $request, int $employee)
    {
        try {
            $data = $this->validateMutation($request);
            $employeeModel = EmployeeDetail::findOrFail($employee);

            $log = $this->pointsService->addPoints($employeeModel->id, $data);
            $log->loadMissing('creator');

            return response()->json([
                'status' => 'success',
                'message' => __('messages.points_updated'),
                'log' => new EmployeePointsLogResource($log),
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

    public function deduct(Request $request, int $employee)
    {
        try {
            $data = $this->validateMutation($request);
            $employeeModel = EmployeeDetail::findOrFail($employee);

            $log = $this->pointsService->deductPoints($employeeModel->id, $data);
            $log->loadMissing('creator');

            return response()->json([
                'status' => 'success',
                'message' => __('messages.points_updated'),
                'log' => new EmployeePointsLogResource($log),
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

    public function logs(Request $request, int $employee)
    {
        try {
            $employeeModel = EmployeeDetail::findOrFail($employee);

            $validated = $request->validate([
                'month' => ['nullable', 'integer', 'between:1,12'],
                'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
                'category' => ['nullable', 'string', 'max:64'],
                'operation_type' => ['nullable', Rule::in([EmployeePointsLog::OPERATION_ADD, EmployeePointsLog::OPERATION_DEDUCT])],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            ]);

            /** @phpstan-ignore-next-line */
            $query = EmployeePointsLog::query()
                ->forEmployee($employeeModel->id)
                ->with('creator:id,name')
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
        return response()->json([
            'status' => 'success',
            'categories' => [
                'positive' => $this->pointsService->positiveCategories(),
                'negative' => $this->pointsService->negativeCategories(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateMutation(Request $request): array
    {
        return $request->validate([
            'points' => ['required', 'integer', 'min:1'],
            'category' => ['required', 'string', 'max:64'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'points_date' => ['nullable', 'date'],
            'source' => ['nullable', Rule::in(config('employee_points.sources', []))],
        ]);
    }
}
