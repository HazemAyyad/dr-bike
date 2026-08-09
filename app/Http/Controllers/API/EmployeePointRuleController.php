<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeePointRuleOverrideResource;
use App\Http\Resources\EmployeePointRuleResource;
use App\Models\EmployeeDetail;
use App\Models\EmployeePointRule;
use App\Models\EmployeePointRuleOverride;
use App\Models\EmployeePointsLog;
use App\Services\EmployeePointRules\EmployeePointRuleEngineService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeePointRuleController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'include_inactive' => ['nullable', 'boolean'],
            'period_type' => ['nullable', Rule::in($this->periodTypes())],
            'condition_type' => ['nullable', Rule::in($this->conditionTypes())],
        ]);

        /** @phpstan-ignore-next-line */
        $query = EmployeePointRule::query()
            ->with(['employees:id', 'overrides.rule'])
            ->orderBy('sort_order')
            ->orderBy('id');

        if (! (bool) ($validated['include_inactive'] ?? false)) {
            $query->active();
        }
        if (! empty($validated['period_type'])) {
            $query->where('period_type', $validated['period_type']);
        }
        if (! empty($validated['condition_type'])) {
            $query->where('condition_type', $validated['condition_type']);
        }

        return response()->json([
            'status' => 'success',
            'rules' => EmployeePointRuleResource::collection($query->get()),
            'condition_types' => $this->conditionTypes(),
            'period_types' => $this->periodTypes(),
        ]);
    }

    public function store(Request $request)
    {
        try {
            $data = $this->validateRule($request);
            $employeeIds = $data['employee_ids'] ?? [];
            unset($data['employee_ids'], $data['effective_policy']);

            $data['created_by'] = Auth::id();

            /** @var EmployeePointRule $rule */
            $rule = EmployeePointRule::create($data);
            if (! $rule->applies_to_all) {
                $rule->employees()->sync($employeeIds);
            }

            return response()->json([
                'status' => 'success',
                'rule' => new EmployeePointRuleResource($rule->fresh(['employees:id', 'overrides'])),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        }
    }

    public function update(Request $request, int $id)
    {
        try {
            /** @var EmployeePointRule $rule */
            $rule = EmployeePointRule::findOrFail($id);
            $data = $this->validateRule($request, true);
            $employeeIds = $data['employee_ids'] ?? null;
            unset($data['employee_ids'], $data['effective_policy']);

            $rule->update($data);
            if (array_key_exists('applies_to_all', $data) || $employeeIds !== null) {
                $rule->employees()->sync($rule->applies_to_all ? [] : ($employeeIds ?? []));
            }

            return response()->json([
                'status' => 'success',
                'rule' => new EmployeePointRuleResource($rule->fresh(['employees:id', 'overrides'])),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => __('messages.not_found')], 200);
        }
    }

    public function destroy(int $id)
    {
        EmployeePointRule::query()->where('id', $id)->delete();

        return response()->json(['status' => 'success']);
    }

    public function run(Request $request, EmployeePointRuleEngineService $engine, ?int $id = null)
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date'],
            'force' => ['nullable', 'boolean'],
        ]);

        $summary = $engine->run(
            ! empty($validated['date']) ? Carbon::parse($validated['date']) : Carbon::now(),
            $id,
            (bool) ($validated['force'] ?? false)
        );

        return response()->json([
            'status' => 'success',
            'summary' => $summary,
        ]);
    }

    public function employeeOverrides(int $employee)
    {
        EmployeeDetail::findOrFail($employee);

        /** @phpstan-ignore-next-line */
        $overrides = EmployeePointRuleOverride::query()
            ->where('employee_id', $employee)
            ->with('rule')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'status' => 'success',
            'overrides' => EmployeePointRuleOverrideResource::collection($overrides),
        ]);
    }

    public function upsertEmployeeOverride(Request $request, int $employee)
    {
        try {
            EmployeeDetail::findOrFail($employee);
            $data = $request->validate([
                'rule_id' => ['required', 'integer', 'exists:employee_point_rules,id'],
                'points' => ['nullable', 'integer', 'min:0'],
                'operation_type' => ['nullable', Rule::in([EmployeePointsLog::OPERATION_ADD, EmployeePointsLog::OPERATION_DEDUCT])],
                'is_excluded' => ['nullable', 'boolean'],
                'effective_policy' => ['nullable', Rule::in(['from_date', 'today', 'current_week', 'current_month'])],
                'effective_from' => ['nullable', 'date'],
                'notes' => ['nullable', 'string'],
            ]);

            $data['employee_id'] = $employee;
            $data['effective_from'] = $this->resolveEffectiveFrom($data);
            unset($data['effective_policy']);

            $override = EmployeePointRuleOverride::create($data);

            return response()->json([
                'status' => 'success',
                'override' => new EmployeePointRuleOverrideResource($override->fresh('rule')),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        }
    }

    public function deleteEmployeeOverride(int $employee, int $override)
    {
        EmployeePointRuleOverride::query()
            ->where('employee_id', $employee)
            ->where('id', $override)
            ->delete();

        return response()->json(['status' => 'success']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateRule(Request $request, bool $isUpdate = false): array
    {
        $rules = [
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'condition_type' => [$isUpdate ? 'sometimes' : 'required', Rule::in($this->conditionTypes())],
            'period_type' => [$isUpdate ? 'sometimes' : 'required', Rule::in($this->periodTypes())],
            'operation_type' => [$isUpdate ? 'sometimes' : 'required', Rule::in([EmployeePointsLog::OPERATION_ADD, EmployeePointsLog::OPERATION_DEDUCT])],
            'default_points' => [$isUpdate ? 'sometimes' : 'required', 'integer', 'min:0'],
            'applies_to_all' => ['nullable', 'boolean'],
            'employee_ids' => ['nullable', 'array'],
            'employee_ids.*' => ['integer', 'exists:employee_details,id'],
            'settings' => ['nullable', 'array'],
            'settings.cutoff_time' => ['nullable', 'date_format:H:i'],
            'effective_policy' => ['nullable', Rule::in(['from_date', 'today', 'current_week', 'current_month'])],
            'effective_from' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];

        $data = $request->validate($rules);
        $data['effective_from'] = $this->resolveEffectiveFrom($data);

        return $data;
    }

    private function resolveEffectiveFrom(array $data): ?string
    {
        $policy = (string) ($data['effective_policy'] ?? 'from_date');

        return match ($policy) {
            'today' => Carbon::now()->toDateString(),
            'current_week' => Carbon::now()->startOfWeek()->toDateString(),
            'current_month' => Carbon::now()->startOfMonth()->toDateString(),
            default => ! empty($data['effective_from'])
                ? Carbon::parse($data['effective_from'])->toDateString()
                : Carbon::now()->toDateString(),
        };
    }

    /**
     * @return array<int,string>
     */
    private function conditionTypes(): array
    {
        return [
            EmployeePointRule::CONDITION_EMPLOYEE_COMPLETED_ALL_TASKS_BEFORE_TIME,
            EmployeePointRule::CONDITION_ALL_EMPLOYEES_COMPLETED_TASKS,
            EmployeePointRule::CONDITION_EMPLOYEE_HAS_INCOMPLETE_TASKS,
        ];
    }

    /**
     * @return array<int,string>
     */
    private function periodTypes(): array
    {
        return [
            EmployeePointRule::PERIOD_DAILY,
            EmployeePointRule::PERIOD_WEEKLY,
            EmployeePointRule::PERIOD_MONTHLY,
        ];
    }
}
